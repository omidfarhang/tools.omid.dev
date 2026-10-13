const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const outCss = path.join(root, 'public/assets/css');
const outJs = path.join(root, 'public/assets/js');

fs.mkdirSync(outCss, { recursive: true });
fs.mkdirSync(outJs, { recursive: true });

const copies = [
  ['node_modules/@tabler/core/dist/css/tabler.min.css', 'public/assets/css/tabler.min.css'],
  ['node_modules/@tabler/core/dist/js/tabler.min.js', 'public/assets/js/tabler.min.js'],
];

const remove = [
  'public/assets/css/bootstrap.min.css',
  'public/assets/js/bootstrap.bundle.min.js',
  'public/assets/js/jquery.min.js',
];

for (const [from, to] of copies) {
  const src = path.join(root, from);
  const dest = path.join(root, to);
  if (!fs.existsSync(src)) {
    console.error('Missing dependency file:', from);
    process.exit(1);
  }
  fs.copyFileSync(src, dest);
  console.log('Copied', to);
}

for (const rel of remove) {
  const file = path.join(root, rel);
  if (fs.existsSync(file)) {
    fs.unlinkSync(file);
    console.log('Removed', rel);
  }
}
