<?php

declare(strict_types=1);

namespace App;

use PDO;

final class WebAuthnCredentialRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function countForUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function hasForUser(int $userId): bool
    {
        return $this->countForUser($userId) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, transports, sign_count, created_at, last_used_at
             FROM webauthn_credentials
             WHERE user_id = :uid
             ORDER BY COALESCE(last_used_at, created_at) DESC'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecordsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, credential_id, public_key, sign_count, transports, label, credential_data,
                    created_at, last_used_at
             FROM webauthn_credentials
             WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    public function findByCredentialId(string $credentialIdB64): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, credential_id, public_key, sign_count, transports, label, credential_data,
                    created_at, last_used_at
             FROM webauthn_credentials
             WHERE credential_id = :cid
             LIMIT 1'
        );
        $stmt->execute([':cid' => $credentialIdB64]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insert(
        int $userId,
        string $credentialIdB64,
        string $publicKeyB64,
        int $signCount,
        ?string $transportsJson,
        string $label,
        string $credentialDataJson,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO webauthn_credentials
                (user_id, credential_id, public_key, sign_count, transports, label, credential_data)
             VALUES (:uid, :cid, :pk, :sc, :tr, :label, :data)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':cid' => $credentialIdB64,
            ':pk' => $publicKeyB64,
            ':sc' => $signCount,
            ':tr' => $transportsJson,
            ':label' => $label,
            ':data' => $credentialDataJson,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateAfterUse(int $id, int $signCount, string $credentialDataJson): void
    {
        $stmt = $this->db->prepare(
            'UPDATE webauthn_credentials
             SET sign_count = :sc, credential_data = :data, last_used_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([
            ':sc' => $signCount,
            ':data' => $credentialDataJson,
            ':id' => $id,
        ]);
    }

    public function deleteForUser(int $userId, int $credentialId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM webauthn_credentials WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $credentialId, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function deleteAllForUser(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM webauthn_credentials WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        return $stmt->rowCount();
    }
}
