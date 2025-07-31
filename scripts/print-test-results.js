const { spawn } = require('child_process');

const phpunit = spawn('php', ['vendor/bin/phpunit', '-c', 'phpunit.xml'], {
  stdio: 'inherit'
});

phpunit.on('exit', (code) => {
  console.log(`Test run completed with exit code ${code}`);
  process.exit(code);
});
