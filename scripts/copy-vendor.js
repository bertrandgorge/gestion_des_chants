// Copie les librairies JS depuis node_modules vers public/assets/js/vendor.
// Les fichiers copiés sont commités : la production (O2Switch) n'a pas besoin de Node.
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const dest = path.join(root, 'public', 'assets', 'js', 'vendor');
fs.mkdirSync(dest, { recursive: true });

const files = [
  ['bootstrap/dist/js/bootstrap.bundle.min.js', 'bootstrap.bundle.min.js'],
  ['sortablejs/Sortable.min.js', 'sortable.min.js'],
];

for (const [src, out] of files) {
  const from = path.join(root, 'node_modules', src);
  const to = path.join(dest, out);
  fs.copyFileSync(from, to);
  console.log(`copié ${out}`);
}
