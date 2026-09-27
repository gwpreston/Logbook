// Copy the pinned front-end libraries from node_modules into assets/vendor.
// Maintainer-only (`npm ci && npm run vendor`); the results are committed so
// neither Docker builds nor bare-PHP installs ever need Node.
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const libs = [
  { pkg: 'alpinejs', file: 'dist/cdn.min.js', out: 'alpine.min.js' },
  { pkg: 'chart.js', file: 'dist/chart.umd.min.js', out: 'chart.umd.min.js' },
  { pkg: 'sortablejs', file: 'Sortable.min.js', out: 'sortable.min.js' },
];

const outDir = join(root, 'assets', 'vendor');
mkdirSync(outDir, { recursive: true });

const notices = ['Third-party libraries bundled with Logbook', ''];
for (const { pkg, file, out } of libs) {
  const pkgDir = join(root, 'node_modules', pkg);
  const meta = JSON.parse(readFileSync(join(pkgDir, 'package.json'), 'utf8'));
  copyFileSync(join(pkgDir, file), join(outDir, out));
  notices.push(`${out}: ${pkg} ${meta.version} — ${meta.license} license — ${meta.homepage || meta.repository?.url || meta.repository || ''}`);
  console.log(`vendored ${pkg}@${meta.version} -> assets/vendor/${out}`);
}
writeFileSync(join(outDir, 'THIRD-PARTY-NOTICES.txt'), notices.join('\n') + '\n');
