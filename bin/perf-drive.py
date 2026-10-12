#!/usr/bin/env python3
"""Performance driver (development only, not shipped). Runs inside the container.

  perf-drive.py <site> <result.json> <hpos> <db> <explain 0|1>

For every step of a browser audit run (start, each check, finish, markdown, JSON export, print export) it starts a fresh PHP
process under the limits being tested, twice: once plain (time, peak memory, query count) and once with SAVEQUERIES
(slowest query, EXPLAIN, repeated query shapes). The plain pass is the one reported for time and memory.
"""
import json
import os
import subprocess
import sys
import time

site, result_path, hpos, db, explain = sys.argv[1:6]
scenario = sys.argv[6] if len(sys.argv) > 6 else ""
PHP = ["php", "-d", "memory_limit=128M", "-d", "max_execution_time=30", "-d", "display_errors=0",
       "-d", "log_errors=0", "-d", "error_reporting=32767", "-d", "sendmail_path=/bin/true"]
HARD_WALL = 120  # seconds; the process is killed here and counted as a timeout
LIMIT_S = 30
LIMIT_MEM = 128 * 1024 * 1024
HIGHLIGHT_S = 5
HIGHLIGHT_MEM = 64 * 1024 * 1024

admin_id = subprocess.run(
    ["php", "-d", "memory_limit=1G", "/wp-cli.phar", "--path=" + site, "user", "list", "--role=administrator", "--field=ID", "--orderby=ID", "--number=1"],
    capture_output=True, text=True, env={**os.environ, "SA_PERF_DB": db}).stdout.strip().split("\n")[0]
if not admin_id.isdigit():
    print("cannot find an administrator", file=sys.stderr)
    sys.exit(2)

base_env = {**os.environ, "SA_PERF_DB": db, "SA_PERF_LOGIN": admin_id, "PERF_EXPLAIN": explain}


def run(step, check="", run_id="", saveq=False):
    out = "/tmp/perf-step-%d.json" % os.getpid()
    try:
        os.remove(out)
    except FileNotFoundError:
        pass
    env = {**base_env, "PERF_OUT": out, "PERF_RUN_ID": run_id, "PERF_SAVEQ": "1" if saveq else "0"}
    cmd = PHP + ["/repo/bin/perf-run.php", site, step] + ([check] if check else [])
    t = time.time()
    timed_out = False
    try:
        p = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=HARD_WALL)
        rc = p.returncode
        stderr = p.stderr[-300:]
    except subprocess.TimeoutExpired:
        timed_out = True
        rc = -9
        stderr = ""
    wall = (time.time() - t) * 1000
    try:
        with open(out) as fh:
            m = json.load(fh)
    except Exception:
        m = {"no_result": True}
    m["rc"] = rc
    m["driver_wall_ms"] = round(wall, 1)
    if stderr.strip():
        m["stderr"] = stderr.strip()
    if timed_out:
        m["fatal_kind"] = "timeout"
        m["fatal"] = "killed by the driver after %d s" % HARD_WALL
    return m


def violations(m):
    v = []
    kind = m.get("fatal_kind")
    if kind == "timeout":
        v.append("timeout")
    elif kind == "memory":
        v.append("memory limit")
    elif kind:
        v.append("fatal error")
    elif m.get("no_result"):
        v.append("no result (rc=%s)" % m.get("rc"))
    if m.get("wall_ms", m.get("driver_wall_ms", 0)) / 1000.0 > LIMIT_S:
        v.append("over %d s" % LIMIT_S)
    if m.get("peak_bytes", 0) > LIMIT_MEM:
        v.append("peak over 128M")
    if m.get("http_json_success") is False:
        v.append("handler error: " + str(m.get("message", "")))
    if m.get("step") in ("check",) and m.get("severity") == "error" and not scenario:
        v.append("check reported error: " + str(m.get("summary", ""))[:80])
    if m.get("message", "").startswith("no handler"):
        v.append(m["message"])
    return v


steps = []
t_all = time.time()


def record(label, m_plain, m_q):
    entry = {"label": label, "plain": m_plain, "queries": m_q, "violations": violations(m_plain)}
    steps.append(entry)
    pm = m_plain
    print("%-34s wall %8.0f ms  handler %8s  peak %5.1f MB  q %4s  %s" % (
        label, pm.get("wall_ms", pm.get("driver_wall_ms", 0)), pm.get("handler_ms", "-"),
        pm.get("peak_bytes", 0) / 1048576.0, pm.get("queries", "-"), ",".join(entry["violations"]) or "ok"), flush=True)


# Baseline: WordPress, WooCommerce and the admin includes, nothing else.
if scenario:
    start = run("start")
    record("start", start, {})
    run_id = start.get("run_id", "")
    checks = start.get("checks", [])
else:
    boot = run("boot")
    record("boot (WordPress + admin)", boot, {})
    start = run("start")
    start_q = run("start", saveq=True)
    record("start", start, start_q)
    run_id = start_q.get("run_id", "")  # the latest start owns the run
    checks = start_q.get("checks", [])
if not run_id or not checks:
    print("start returned no run id or no checks", file=sys.stderr)

for cid in checks:
    # a fresh run id is not needed: results are overwritten per check
    plain = run("check", cid, run_id)
    withq = {} if scenario else run("check", cid, run_id, saveq=True)
    record("check " + cid, plain, withq)

if scenario:
    fin = run("finish", "", run_id)
    record("finish", fin, {})
else:
    # finish deletes the run; keep a copy of the run state so the SAVEQUERIES pass can run it again.
    state = "/tmp/perf-state-%d.sql" % os.getpid()
    with open(state, "w") as fh:
        subprocess.run(["mysqldump", "-h", "db", "-uroot", "-pwordpress", "--no-create-info", "--replace", "--skip-extended-insert",
                        "--where=option_name LIKE 'store\\_audit\\_%'", db, "wp_options"], stdout=fh, stderr=subprocess.DEVNULL)
    fin = run("finish", "", run_id)
    subprocess.run("mysql -h db -uroot -pwordpress %s < %s" % (db, state), shell=True, stderr=subprocess.DEVNULL)
    fin_q = run("finish", "", run_id, saveq=True)
    record("finish", fin, fin_q)
    # markdown and exports read the stored report; the report exists after finish
    md = run("markdown")
    md_q = run("markdown", saveq=True)
    record("markdown (copy button)", md, md_q)
    dl = run("download")
    dl_q = run("download", saveq=True)
    record("export JSON", dl, dl_q)
    pr = run("print")
    pr_q = run("print", saveq=True)
    record("export printable HTML", pr, pr_q)


def wp_json(*args):
    r = subprocess.run(["php", "-d", "memory_limit=1G", "/wp-cli.phar", "--path=" + site] + list(args),
                       capture_output=True, text=True, env={**os.environ, "SA_PERF_DB": db})
    try:
        return json.loads(r.stdout.strip().split("\n")[-1])
    except Exception:
        return None


counts = wp_json("option", "get", "sa_perf_counts", "--format=json")
tables = {}
tr = subprocess.run(["mysql", "-h", "db", "-uroot", "-pwordpress", "-N", "-e",
                     "SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576) FROM information_schema.TABLES WHERE TABLE_SCHEMA='%s' AND TABLE_ROWS > 1000 ORDER BY TABLE_ROWS DESC" % db],
                    capture_output=True, text=True)
for line in tr.stdout.strip().split("\n"):
    parts = line.split("\t")
    if len(parts) == 3:
        tables[parts[0]] = {"rows": int(parts[1]), "mb": int(parts[2])}
php_v = subprocess.run(["php", "-r", "echo PHP_VERSION;"], capture_output=True, text=True).stdout
my_v = subprocess.run(["mysql", "-h", "db", "-uroot", "-pwordpress", "-N", "-e", "SELECT VERSION(), @@innodb_buffer_pool_size"], capture_output=True, text=True).stdout.strip().split("\t")

summary = {
    "scenario": scenario,
    "counts": counts,
    "tables": tables,
    "php": php_v,
    "mysql": my_v,
    "hpos": hpos,
    "db": db,
    "steps": steps,
    "total_s": round(time.time() - t_all, 1),
    "violations": sum(len(s["violations"]) for s in steps),
}
with open(result_path, "w") as fh:
    json.dump(summary, fh)
print("driver done: %d steps, %d violations, %.0f s" % (len(steps), summary["violations"], summary["total_s"]))
