<?php

declare(strict_types=1);

namespace App;

use Cose\Algorithms;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebAuthnService
{
    private const SESSION_CREATION = 'webauthn_creation_options';
    private const SESSION_REQUEST = 'webauthn_request_options';

    private SerializerInterface $serializer;
    private AuthenticatorAttestationResponseValidator $attestationValidator;
    private AuthenticatorAssertionResponseValidator $assertionValidator;
    private WebAuthnCredentialRepository $credentials;
    private string $rpId;
    private string $rpName;
    private string $origin;
    private string $host;

    public function __construct(?WebAuthnCredentialRepository $credentials = null)
    {
        $this->credentials = $credentials ?? new WebAuthnCredentialRepository();
        $this->rpName = (string) Config::get('app_name', 'Tools Center');
        $base = Config::baseUrl();
        $parts = parse_url($base);
        $this->rpId = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'localhost';
        $scheme = is_array($parts) && isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;
        $this->origin = self::buildOrigin($scheme, $this->rpId, $port);
        $this->host = $this->rpId;

        $attestationManager = AttestationStatementSupportManager::create([
            NoneAttestationStatementSupport::create(),
        ]);
        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        $csmFactory = new CeremonyStepManagerFactory();
        $csmFactory->setAllowedOrigins([$this->origin]);
        if ($scheme === 'http') {
            $csmFactory->setSecuredRelyingPartyId([$this->rpId]);
        }

        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $csmFactory->creationCeremony()
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $csmFactory->requestCeremony()
        );
    }

    /**
     * @return array<string, mixed> PublicKeyCredentialCreationOptions as JSON-compatible array
     */
    public function creationOptions(array $user): array
    {
        $userId = (int) $user['id'];
        $username = (string) $user['username'];
        $exclude = [];
        foreach ($this->credentials->listRecordsForUser($userId) as $row) {
            $record = $this->deserializeCredential((string) $row['credential_data']);
            $exclude[] = $record->getPublicKeyCredentialDescriptor();
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId),
            PublicKeyCredentialUserEntity::create(
                $username,
                $this->userHandle($userId),
                $username,
            ),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            60000,
        );

        $_SESSION[self::SESSION_CREATION] = $this->serializeOptions($options);

        return $this->optionsToArray($options);
    }

    /**
     * @return array{ok: true, id: int, label: string}|array{ok: false, error: string}
     */
    public function completeRegistration(array $user, string $credentialJson, string $label): array
    {
        $optionsJson = $_SESSION[self::SESSION_CREATION] ?? null;
        if (!is_string($optionsJson) || $optionsJson === '') {
            return ['ok' => false, 'error' => 'No passkey registration in progress. Try again.'];
        }

        try {
            $options = $this->serializer->deserialize(
                $optionsJson,
                PublicKeyCredentialCreationOptions::class,
                'json'
            );
            $publicKeyCredential = $this->serializer->deserialize(
                $credentialJson,
                PublicKeyCredential::class,
                'json'
            );
            if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
                return ['ok' => false, 'error' => 'Invalid registration response.'];
            }

            $record = $this->attestationValidator->check(
                $publicKeyCredential->response,
                $options,
                $this->host,
            );

            $credentialIdB64 = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);
            if ($this->credentials->findByCredentialId($credentialIdB64) !== null) {
                return ['ok' => false, 'error' => 'This passkey is already registered.'];
            }

            $label = trim($label);
            if ($label === '') {
                $label = 'Passkey';
            }
            if (strlen($label) > 80) {
                $label = substr($label, 0, 77) . '...';
            }

            $dataJson = $this->serializeCredential($record);
            $id = $this->credentials->insert(
                (int) $user['id'],
                $credentialIdB64,
                Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
                $record->counter,
                $record->transports !== [] ? json_encode(array_values($record->transports), JSON_THROW_ON_ERROR) : null,
                $label,
                $dataJson,
            );

            unset($_SESSION[self::SESSION_CREATION]);

            return ['ok' => true, 'id' => $id, 'label' => $label];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not register passkey. ' . $this->safeMessage($e)];
        }
    }

    /**
     * @return array<string, mixed>|array{ok: false, error: string}
     */
    public function assertionOptions(int $userId): array
    {
        $rows = $this->credentials->listRecordsForUser($userId);
        if ($rows === []) {
            return ['ok' => false, 'error' => 'No passkeys registered.'];
        }

        $allow = [];
        foreach ($rows as $row) {
            $record = $this->deserializeCredential((string) $row['credential_data']);
            $allow[] = $record->getPublicKeyCredentialDescriptor();
        }

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId,
            $allow,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60000,
        );

        $_SESSION[self::SESSION_REQUEST] = $this->serializeOptions($options);

        return $this->optionsToArray($options);
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function completeAssertion(int $userId, string $credentialJson): array
    {
        $optionsJson = $_SESSION[self::SESSION_REQUEST] ?? null;
        if (!is_string($optionsJson) || $optionsJson === '') {
            return ['ok' => false, 'error' => 'No passkey challenge in progress. Try again.'];
        }

        try {
            $options = $this->serializer->deserialize(
                $optionsJson,
                PublicKeyCredentialRequestOptions::class,
                'json'
            );
            $publicKeyCredential = $this->serializer->deserialize(
                $credentialJson,
                PublicKeyCredential::class,
                'json'
            );
            if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
                return ['ok' => false, 'error' => 'Invalid authentication response.'];
            }

            $credentialIdB64 = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);
            $row = $this->credentials->findByCredentialId($credentialIdB64);
            if ($row === null || (int) $row['user_id'] !== $userId) {
                return ['ok' => false, 'error' => 'Unknown passkey.'];
            }

            $stored = $this->deserializeCredential((string) $row['credential_data']);
            $updated = $this->assertionValidator->check(
                $stored,
                $publicKeyCredential->response,
                $options,
                $this->host,
                $this->userHandle($userId),
            );

            $this->credentials->updateAfterUse(
                (int) $row['id'],
                $updated->counter,
                $this->serializeCredential($updated),
            );

            unset($_SESSION[self::SESSION_REQUEST]);

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Passkey verification failed. ' . $this->safeMessage($e)];
        }
    }

    public static function clearCeremony(): void
    {
        unset($_SESSION[self::SESSION_CREATION], $_SESSION[self::SESSION_REQUEST]);
    }

    private function userHandle(int $userId): string
    {
        return pack('N', $userId);
    }

    private function serializeOptions(object $options): string
    {
        return $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsToArray(object $options): array
    {
        $json = $this->serializeOptions($options);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to encode WebAuthn options.');
        }

        return $decoded;
    }

    private function serializeCredential(CredentialRecord $record): string
    {
        return $this->serializer->serialize($record, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }

    private function deserializeCredential(string $json): CredentialRecord
    {
        return $this->serializer->deserialize($json, CredentialRecord::class, 'json');
    }

    private function safeMessage(Throwable $e): string
    {
        $msg = trim($e->getMessage());
        if ($msg === '' || strlen($msg) > 160) {
            return 'Please try again.';
        }

        return $msg;
    }

    private static function buildOrigin(string $scheme, string $host, ?int $port): string
    {
        $origin = $scheme . '://' . $host;
        if ($port !== null) {
            $default = $scheme === 'https' ? 443 : 80;
            if ($port !== $default) {
                $origin .= ':' . $port;
            }
        }

        return $origin;
    }
}
