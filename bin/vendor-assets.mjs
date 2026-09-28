// Copy the pinned front-end libraries, fonts and icons from node_modules into
// assets/vendor. Maintainer-only (`npm ci && npm run vendor`); the results are
// committed so neither Docker builds nor bare-PHP installs ever need Node, and
// the app never loads anything from a third-party CDN at runtime.
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'assets', 'vendor');
const meta = (pkg) => JSON.parse(readFileSync(join(root, 'node_modules', pkg, 'package.json'), 'utf8'));
const source = (m) =>
  String(m.homepage || m.repository?.url || m.repository || '').replace(/^git\+|\.git$/g, '').replace(/^git:/, 'https:');

const libs = [
  { pkg: 'alpinejs', file: 'dist/cdn.min.js', out: 'alpine.min.js' },
  { pkg: 'chart.js', file: 'dist/chart.umd.min.js', out: 'chart.umd.min.js' },
  { pkg: 'sortablejs', file: 'Sortable.min.js', out: 'sortable.min.js' },
];

// Variable fonts, Latin + Latin Extended subsets (@font-face rules in app.css).
const fonts = [
  { pkg: '@fontsource-variable/outfit', files: ['outfit-latin-wght-normal.woff2', 'outfit-latin-ext-wght-normal.woff2'] },
  {
    pkg: '@fontsource-variable/plus-jakarta-sans',
    files: ['plus-jakarta-sans-latin-wght-normal.woff2', 'plus-jakarta-sans-latin-ext-wght-normal.woff2'],
  },
];

// Material Symbols (Rounded, weight 400) used by the templates, bundled into one
// SVG sprite: <svg><use href="…/icons.svg#name"/></svg>. Add names here as
// templates need them, then re-run `npm run vendor`.
const iconPkg = '@material-symbols/svg-400';
const icons = [
  'account_balance',
  'add',
  'archive',
  'arrow_downward',
  'arrow_forward',
  'arrow_upward',
  'attach_file',
  'badge',
  'bar_chart',
  'battery_charging_full',
  'build',
  'calendar_month',
  'car_repair',
  'check',
  'check_circle',
  'chevron_left',
  'chevron_right',
  'close',
  'contrast',
  'dark_mode',
  'delete',
  'description',
  'directions_car',
  'download',
  'drag_indicator',
  'eco',
  'edit',
  'error',
  'ev_station',
  'event_repeat',
  'fact_check',
  'format_paint',
  'garage',
  'gavel',
  'handyman',
  'image',
  'info',
  'inventory_2',
  'light_mode',
  'local_car_wash',
  'local_gas_station',
  'local_parking',
  'lock',
  'login',
  'logout',
  'notifications',
  'oil_barrel',
  'payments',
  'person',
  'picture_as_pdf',
  'receipt_long',
  'restart_alt',
  'send',
  'settings',
  'shopping_bag',
  'space_dashboard',
  'speed',
  'stop_circle',
  'tire_repair',
  'toll',
  'trending_up',
  'tune',
  'two_wheeler',
  'unarchive',
  'undo',
  'verified_user',
  'visibility',
  'visibility_off',
  'warning',
];

mkdirSync(join(outDir, 'fonts'), { recursive: true });
const notices = ['Third-party libraries bundled with Logbook', ''];

for (const { pkg, file, out } of libs) {
  const m = meta(pkg);
  copyFileSync(join(root, 'node_modules', pkg, file), join(outDir, out));
  notices.push(`${out}: ${pkg} ${m.version} — ${m.license} license — ${source(m)}`);
  console.log(`vendored ${pkg}@${m.version} -> assets/vendor/${out}`);
}

for (const { pkg, files } of fonts) {
  const m = meta(pkg);
  for (const file of files) {
    copyFileSync(join(root, 'node_modules', pkg, 'files', file), join(outDir, 'fonts', file));
  }
  notices.push(`fonts/${files[0].replace(/-latin-.*$/, '')}-*.woff2: ${pkg} ${m.version} — ${m.license} license — ${source(m)}`);
  console.log(`vendored ${pkg}@${m.version} -> assets/vendor/fonts/ (${files.length} files)`);
}

const symbols = icons.map((name) => {
  const svg = readFileSync(join(root, 'node_modules', iconPkg, 'rounded', `${name}.svg`), 'utf8');
  const viewBox = /viewBox="([^"]+)"/.exec(svg)?.[1];
  const body = /<svg[^>]*>([\s\S]*)<\/svg>/.exec(svg)?.[1];
  if (!viewBox || !body) {
    throw new Error(`cannot parse icon ${name}`);
  }
  return `<symbol id="${name}" viewBox="${viewBox}">${body.trim()}</symbol>`;
});
writeFileSync(
  join(outDir, 'icons.svg'),
  `<svg xmlns="http://www.w3.org/2000/svg">\n${symbols.join('\n')}\n</svg>\n`,
);
const im = meta(iconPkg);
notices.push(`icons.svg: ${iconPkg} ${im.version} (Material Symbols Rounded) — ${im.license} license — ${source(im)}`);
console.log(`vendored ${icons.length} icons from ${iconPkg}@${im.version} -> assets/vendor/icons.svg`);

writeFileSync(join(outDir, 'THIRD-PARTY-NOTICES.txt'), notices.join('\n') + '\n');
