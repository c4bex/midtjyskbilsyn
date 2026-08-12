import { accessSync, constants } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";
import { spawn, spawnSync } from "node:child_process";

const candidates = [
  process.env.MB_PHP_BINARY,
  "php",
  join(homedir(), "Library/Application Support/Herd/bin/php84"),
  join(homedir(), "Library/Application Support/Herd/bin/php85"),
].filter(Boolean);

const isUsable = (candidate) => {
  if (candidate === "php") return spawnSync(candidate, ["-v"], { stdio: "ignore" }).status === 0;
  try { accessSync(candidate, constants.X_OK); return true; } catch { return false; }
};
const php = candidates.find(isUsable);
if (!php) {
  console.error("PHP 8.4+ blev ikke fundet. Installér eller åbn Laravel Herd og prøv igen.");
  process.exit(1);
}

const children = [
  spawn(php, ["artisan", "serve", "--host=127.0.0.1", "--port=8000"], { cwd: "backend", stdio: "inherit" }),
  spawn(php, ["artisan", "queue:listen", "--tries=1", "--timeout=0"], { cwd: "backend", stdio: "inherit" }),
  spawn("npm", ["run", "dev"], { cwd: ".", stdio: "inherit" }),
];

let stopping = false;
const stop = (code = 0) => {
  if (stopping) return;
  stopping = true;
  for (const child of children) if (!child.killed) child.kill("SIGTERM");
  setTimeout(() => process.exit(code), 250);
};
for (const signal of ["SIGINT", "SIGTERM"]) process.on(signal, () => stop(0));
for (const child of children) child.on("exit", (code) => { if (!stopping && code) stop(code); });
