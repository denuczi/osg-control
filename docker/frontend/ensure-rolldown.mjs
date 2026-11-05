// npm can silently skip optional native packages. Repair the Debian binding
// explicitly, using the version required by the installed Rolldown package.
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const require = createRequire(resolve('package.json'));
const rolldown = JSON.parse(readFileSync('node_modules/rolldown/package.json', 'utf8'));
const binding = `@rolldown/binding-linux-${process.arch}-gnu`;
const version = rolldown.optionalDependencies?.[binding];
if (!version) {
  throw new Error(`Rolldown ${rolldown.version} no declara soporte para ${binding}`);
}

try {
  require(binding);
} catch {
  console.log(`Instalando el componente nativo requerido: ${binding}@${version}`);
  execFileSync('npm', [
    'install', '--no-save', '--include=optional', '--no-audit', '--no-fund',
    `${binding}@${version}`,
  ], { stdio: 'inherit' });
}

// Use a fresh process: Node may cache the failed lookup from before installation.
// Fail here with the original loader error if the native package cannot load.
execFileSync(process.execPath, ['--input-type=commonjs', '-e',
  `require(${JSON.stringify(binding)}); ` +
  `import(${JSON.stringify(require.resolve('rolldown'))}).catch(error => { console.error(error); process.exitCode = 1; });`,
], { stdio: 'inherit' });
