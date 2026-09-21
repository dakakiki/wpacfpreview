/**
 * Build for the wpadm plugin.
 *
 * SCSS → assets/css/*.min.css   (sass + postcss)
 * JS   → assets/js/*.min.js     (terser)
 *
 * Output is committed so the plugin runs from a zip without npm.
 */

import fs from "fs";
import path from "path";
import { execFile } from "child_process";
import { promisify } from "util";
import { minify } from "terser";

const execFileAsync = promisify(execFile);

const isProd = process.env.NODE_ENV === "production";
const root = process.cwd();
const npx = process.platform === "win32" ? "npx.cmd" : "npx";

const styles = [
    { in: "src/scss/wpadm.scss", out: "assets/css/wpadm.min.css" },
];

const scripts = [
    { in: "src/js/wpadm_flex_preview.js", out: "assets/js/wpadm_flex_preview.min.js" },
];

function ensureDir(filePath) {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
}

async function buildStyle({ in: inputRel, out: outputRel }) {
    const inputPath = path.join(root, inputRel);
    const outputPath = path.join(root, outputRel);

    if (!fs.existsSync(inputPath)) {
        console.warn(`[skip] ${inputRel} not found`);
        return;
    }

    ensureDir(outputPath);

    // Sass writes an unminified file to temp, postcss minifies it to the final path.
    const tmpPath = outputPath.replace(/\.min\.css$/, ".tmp.css");

    await execFileAsync(npx, ["sass", inputPath, tmpPath, "--no-source-map"]);
    await execFileAsync(npx, ["postcss", tmpPath, "-o", outputPath]);

    fs.unlinkSync(tmpPath);

    console.log(`[css] ${inputRel} → ${outputRel}`);
}

async function buildScript({ in: inputRel, out: outputRel }) {
    const inputPath = path.join(root, inputRel);
    const outputPath = path.join(root, outputRel);

    if (!fs.existsSync(inputPath)) {
        console.warn(`[skip] ${inputRel} not found`);
        return;
    }

    const code = fs.readFileSync(inputPath, "utf8");

    const result = await minify(code, {
        compress: isProd
            ? { passes: 2, drop_console: ["log", "info"], drop_debugger: true, dead_code: true }
            : { passes: 1, dead_code: true },
        mangle: { safari10: true },
        format: { comments: false },
    });

    if (!result.code) {
        throw new Error(`Minification failed for ${inputRel}`);
    }

    ensureDir(outputPath);
    fs.writeFileSync(outputPath, result.code, "utf8");

    console.log(`[js] ${inputRel} → ${outputRel}`);
}

async function run() {
    const only = process.argv[2];

    if (only !== "js") {
        for (const entry of styles) {
            await buildStyle(entry);
        }
    }

    if (only !== "css") {
        for (const entry of scripts) {
            await buildScript(entry);
        }
    }
}

run().catch((err) => {
    console.error(err);
    process.exit(1);
});
