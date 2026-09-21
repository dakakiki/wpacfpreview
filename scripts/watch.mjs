/**
 * Watch for the wpadm plugin.
 *
 * Runs a targeted build (css or js) depending on which file changed, so a
 * SCSS edit does not trigger a pointless JS minify and vice versa.
 */

import chokidar from "chokidar";
import { exec } from "child_process";

const targets = [
    { glob: "src/scss/**/*.scss", task: "build:css", label: "css" },
    { glob: "src/js/**/*.js", task: "build:js", label: "js" },
];

const running = new Set();
const queued = new Set();

function runTask(task, label) {
    if (running.has(task)) {
        queued.add(task);
        return;
    }

    running.add(task);

    exec(`npm run ${task}`, (err, stdout, stderr) => {
        if (stdout) process.stdout.write(stdout);
        if (stderr) process.stderr.write(stderr);
        if (err) console.error(err);

        running.delete(task);

        if (queued.has(task)) {
            queued.delete(task);
            runTask(task, label);
        }
    });
}

for (const { glob, task, label } of targets) {
    console.log(`[watch:${label}] ${glob}`);

    chokidar.watch(glob, { ignoreInitial: true }).on("all", (event, file) => {
        console.log(`[watch:${label}] ${event} → ${file}`);
        runTask(task, label);
    });
}
