// Copie les librairies front depuis node_modules vers public/assets.
// Les fichiers copiés sont commités : la production (O2Switch) n'a pas besoin de Node.
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');

// --- JS ---------------------------------------------------------------
const jsDest = path.join(root, 'public', 'assets', 'js', 'vendor');
fs.mkdirSync(jsDest, { recursive: true });

const jsFiles = [
  ['bootstrap/dist/js/bootstrap.bundle.min.js', 'bootstrap.bundle.min.js'],
  ['sortablejs/Sortable.min.js', 'sortable.min.js'],
];

for (const [src, out] of jsFiles) {
  fs.copyFileSync(path.join(root, 'node_modules', src), path.join(jsDest, out));
  console.log(`copié js/vendor/${out}`);
}

// --- Polices d'icônes (Bootstrap Icons, référencées par app.css) ------
const fontDest = path.join(root, 'public', 'assets', 'css', 'fonts');
fs.mkdirSync(fontDest, { recursive: true });

const fontFiles = [
  ['bootstrap-icons/font/fonts/bootstrap-icons.woff2', 'bootstrap-icons.woff2'],
  ['bootstrap-icons/font/fonts/bootstrap-icons.woff', 'bootstrap-icons.woff'],
];

for (const [src, out] of fontFiles) {
  fs.copyFileSync(path.join(root, 'node_modules', src), path.join(fontDest, out));
  console.log(`copié css/fonts/${out}`);
}
