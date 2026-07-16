const { execSync } = require('child_process');
try {
    execSync('node seed.js', { stdio: 'pipe' });
} catch (e) {
    console.log(e.stdout.toString());
    console.log(e.stderr.toString());
}
