#!/bin/bash
# Multi-plugin integration run of the reviewed merge workflow with the REAL tool_mergeusers (audit item AR-03).
#
#   tests/integration/run.sh <45|53> [pgsql|mariadb ...]        (default: pgsql mariadb, one after the other)
#
# Isolation (never the shared main or PHPUnit databases, never the shared code trees):
#  * a PRIVATE copy of the Moodle tree is made with hard links (rsync --link-dest, no extra disk space for core files);
#    the family plugins are copied (not linked) from $LTIAM_WORK (default /home/claude/work) and tool_mergeusers from
#    $LTIAM_MERGEUSERS (default /home/claude/ref/moodle-tool_mergeusers). Nothing is written into the shared tree, so no
#    runner lock is needed (the lead's gates/run_tree.sh, which installs tool_mergeusers into the shared tree, takes them);
#  * scratch databases <tree db name>_ltiam (dropped and recreated) and dataroots under $LTIAM_BASE.
# Scenarios per database: see tests/integration/README.md. Output: $LTIAM_OUT/<run>/<tree>/<db>/ (default /tmp/ltiam_integration).
set -uo pipefail
TREE=${1:?tree 45 or 53}; shift; DBS=("$@"); [ ${#DBS[@]} = 0 ] && DBS=(pgsql mariadb)
HERE=$(cd "$(dirname "$0")" && pwd)
WORK=${LTIAM_WORK:-/home/claude/work}
REF=${LTIAM_MERGEUSERS:-/home/claude/ref/moodle-tool_mergeusers}
BASE=${LTIAM_BASE:-/home/claude/data/ltiam_int}
RUN=${LTIAM_RUN:-$(date +%Y%m%d-%H%M%S)}
OUTROOT=${LTIAM_OUT:-/tmp/ltiam_integration}
case $TREE in
  45) SRC=/home/claude/moodle; NAME=moodle; PGPORT=5432;;
  53) SRC=/home/claude/moodle53; NAME=moodle53; PGPORT=5433;;
  *) echo "tree must be 45 or 53"; exit 2;;
esac
PRIV=$BASE/tree$TREE/$NAME          # the basename selects the port map in config.php ("moodle53" = 5.3)
WEB=$PRIV; [ -d $SRC/public ] && WEB=$PRIV/public
DBNAME=${NAME}_ltiam
MERGE="php $WEB/local/ltiaccountmerge/cli/merge.php"
export COMPOSER_ALLOW_SUPERUSER=1; unset JAVA_TOOL_OPTIONS
log() { echo "[$(date +%H:%M:%S)] $*"; }
declare -A REL=([local_ltiaccountmerge]=local/ltiaccountmerge [local_lticompletionsync]=local/lticompletionsync
  [local_ltiidentity]=local/ltiidentity [local_ltixsembed]=local/ltixsembed [ltisource_ltipseudonym]=mod/lti/source/ltipseudonym
  [tool_ltidiagnostics]=admin/tool/ltidiagnostics [tool_ltigroupautoenrol]=admin/tool/ltigroupautoenrol)

# ------------------------------------------------------------------ private tree
log "private tree $PRIV (hard links into $SRC)"
mkdir -p "$PRIV"
EXCL=(--exclude=.git --exclude=node_modules --exclude=/admin/tool/mergeusers)
# vendor/ is linked as well: the 4.5 test data generators of questions load PHPUnit classes.
for c in "${!REL[@]}"; do EXCL+=(--exclude="/$( [ -d $SRC/public ] && echo public/ )${REL[$c]}"); done
rsync -a --delete --link-dest="$SRC" "${EXCL[@]}" "$SRC/" "$PRIV/" || { echo "tree copy failed"; exit 1; }
for c in "${!REL[@]}"; do
  if [ -f "$WORK/moodle-$c/version.php" ]; then
    mkdir -p "$WEB/${REL[$c]}"; rsync -a --delete --exclude=.git --exclude=node_modules "$WORK/moodle-$c/" "$WEB/${REL[$c]}/"
  fi
done
mkdir -p "$WEB/admin/tool/mergeusers"; rsync -a --delete --exclude=.git "$REF/" "$WEB/admin/tool/mergeusers/"
OUT0=$OUTROOT/$RUN/$TREE; mkdir -p "$OUT0"
for c in "${!REL[@]}" tool_mergeusers; do
  d=$WEB/${REL[$c]:-admin/tool/mergeusers}
  echo "$c $(grep -hE 'plugin->(version|release)' $d/version.php 2>/dev/null | tr -s ' ' | tr '\n' ' ')"
done | sort > "$OUT0/plugins_in_tree.txt"
(cd "$REF" && git log -1 --format='%H %cd' 2>/dev/null) > "$OUT0/mergeusers_rev.txt"

sq() { if [ $1 = pgsql ]; then PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d "${3:-$LTI_DBNAME}" -Atc "$2"
       else mariadb -h127.0.0.1 -umoodle -pmoodle -N "${3:-$LTI_DBNAME}" -e "$2"; fi; }
# blocker <db> <tag> <select ...> : holds the row lock of the selected rows (FOR UPDATE) in an open transaction until
# released with unblock <db> <tag>.
blocker() {
  local db=$1 tag=$2 sel=$3
  if [ $db = pgsql ]; then
    PGAPPNAME=$tag PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d $LTI_DBNAME -qc \
      "BEGIN; $sel FOR UPDATE; SELECT pg_sleep(300); COMMIT;" >/dev/null 2>&1 &
  else
    mariadb -h127.0.0.1 -umoodle -pmoodle $LTI_DBNAME -e "START TRANSACTION; $sel FOR UPDATE; SELECT SLEEP(300) AS $tag; COMMIT;" \
      >/dev/null 2>&1 &
  fi
}
unblock() {
  local db=$1 tag=$2
  if [ $db = pgsql ]; then
    sq pgsql "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name = '$tag'" postgres >/dev/null
  else
    for id in $(sq mariadb "SELECT ID FROM information_schema.PROCESSLIST WHERE INFO LIKE '%AS $tag%' AND ID <> CONNECTION_ID()"); do
      sq mariadb "KILL $id" >/dev/null 2>&1
    done
  fi
}
# waitfor <seconds> <command...> : true when the command succeeded within the time.
waitfor() { local until=$(( $(date +%s) + $1 )); shift; while [ $(date +%s) -lt $until ]; do "$@" && return 0; sleep 0.5; done; return 1; }
# jobhas <source> <target> <regex> : the newest job of the pair matches "<status> inmerge=<n> log=<id>".
jobhas() { php $HERE/step.php jobstate $1 $2 | grep -qE "$3"; }
hashof() { php -r 'echo json_decode(stream_get_contents(STDIN),true)["hash"] ?? "";'; }
jget() { php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$j=$j[$k]??null;} echo is_scalar($j)?$j:json_encode($j);' "$1" "$2"; }

# fresh_site <db> <outdir> : drop and recreate the scratch database (LTI_DBNAME) and dataroot (LTI_BASE), install Moodle.
fresh_site() {
  local db=$1 O=$2
  if [ $db = pgsql ]; then sq pgsql "DROP DATABASE IF EXISTS $LTI_DBNAME" postgres && sq pgsql "CREATE DATABASE $LTI_DBNAME OWNER moodle ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0" postgres
  else sq mariadb "DROP DATABASE IF EXISTS $LTI_DBNAME; CREATE DATABASE $LTI_DBNAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" mysql; fi
  rm -rf "$LTI_BASE"; mkdir -p "$LTI_BASE/data"; chmod -R 777 "$LTI_BASE"
  log "[$db] install $LTI_DBNAME"
  php admin/cli/install_database.php --agree-license --lang=en --adminuser=admin --adminpass='Ltiam#Admin2026' \
    --adminemail=admin@example.org --fullname='LTI merge integration' --shortname=ltiam > $O/install.log 2>&1 \
    || { echo "install failed"; tail $O/install.log; return 1; }
}

scenario() {
  local db=$1 O=$OUT0/$1; mkdir -p "$O"
  export LTIAM_ROOT=$PRIV LTI_DB=$db LTI_DBNAME=$DBNAME LTI_BASE=$BASE/data${TREE}_$db
  local STEP="php $HERE/step.php" VERIFY="php $HERE/verify.php"
  cd "$PRIV"
  fresh_site $db $O || return 1
  php $HERE/seed.php $O/seed.json > $O/seed.txt 2>&1 || { echo "seed failed"; tail -20 $O/seed.txt; return 1; }
  S=$O/seed.json; uid() { jget $S ids.user_$1; }
  $STEP snapshot $O/s0.json

  # -------------------------------------------------------------- consent rule inside the real merge transaction
  log "[$db] direct merges in tool_mergeusers (no consent)"
  $STEP direct $(uid b5) $(uid a5) $O/direct5.json > $O/direct5.txt 2>&1; $STEP snapshot $O/s1.json
  $VERIFY direct-refused $S $O/s0.json $O/s1.json $O/direct5.json > $O/v_direct5.txt 2>&1
  $STEP direct $(uid y1) $(uid x1) $O/directx.json > $O/directx.txt 2>&1; $STEP snapshot $O/s2.json
  $VERIFY direct-allowed $S $O/s1.json $O/s2.json $O/directx.json > $O/v_directx.txt 2>&1

  # -------------------------------------------------------------- pair 1: preview, refusals, consent, merge
  log "[$db] pair 1"
  $MERGE --source=$(uid a1) --target=$(uid b1) --preview > $O/p1.json 2>&1
  $VERIFY preview $S $O/p1.json any > $O/v_p1.txt 2>&1
  if [ "$(jget $O/p1.json verdict)" = blocked ]; then
    # Only undeclared plugin tables may be declared by the administrator; any other blocker stays a failure.
    $STEP declare-undeclared $O/p1.json $O/declare.json > $O/declare.txt 2>&1
    $MERGE --source=$(uid a1) --target=$(uid b1) --preview > $O/p1b.json 2>&1
    $VERIFY preview $S $O/p1b.json ready > $O/v_p1b.txt 2>&1
    P1=$O/p1b.json
  else P1=$O/p1.json; fi
  H1=$(jget $P1 hash)
  $MERGE --source=$(uid a1) --target=$(uid b1) --consent=0000 --confirm=ltiam_a1 --run > $O/c1_stale.json 2>&1
  $VERIFY refused $S $O/c1_stale.json err_merge_stale > $O/v_c1_stale.txt 2>&1
  $MERGE --source=$(uid a1) --target=$(uid b1) --consent=$H1 --confirm=ltiam_b1 --run > $O/c1_confirm.json 2>&1
  $VERIFY refused $S $O/c1_confirm.json err_merge_confirmation > $O/v_c1_confirm.txt 2>&1
  $STEP snapshot $O/s3.json
  $MERGE --source=$(uid a1) --target=$(uid b1) --consent=$H1 --confirm=ltiam_a1 --run > $O/c1.json 2>&1
  $STEP snapshot $O/s4.json
  $VERIFY pair1 $S $O/s3.json $O/s4.json $O/c1.json > $O/v_pair1.txt 2>&1

  # -------------------------------------------------------------- pair 2: conflicts
  log "[$db] pair 2 (conflicts)"
  $MERGE --source=$(uid a2) --target=$(uid b2) --preview > $O/p2.json 2>&1
  $VERIFY preview $S $O/p2.json conflicts pair2 > $O/v_p2.txt 2>&1
  H2=$(jget $O/p2.json hash)
  $MERGE --source=$(uid a2) --target=$(uid b2) --consent=$H2 --confirm=ltiam_a2 --run > $O/c2_noaccept.json 2>&1
  $VERIFY refused $S $O/c2_noaccept.json err_merge_conflicts > $O/v_c2_noaccept.txt 2>&1
  $STEP snapshot $O/s5.json
  $MERGE --source=$(uid a2) --target=$(uid b2) --consent=$H2 --confirm=ltiam_a2 --acceptconflicts --run > $O/c2.json 2>&1
  $STEP snapshot $O/s6.json
  $VERIFY pair2 $S $O/s5.json $O/s6.json $O/c2.json > $O/v_pair2.txt 2>&1

  # -------------------------------------------------------------- pair 3: process killed inside the merge transaction
  log "[$db] pair 3 (crash inside the transaction)"
  local A3=$(uid a3) B3=$(uid b3)
  H3=$($MERGE --source=$A3 --target=$B3 --preview | hashof)
  $MERGE --source=$A3 --target=$B3 --consent=$H3 --confirm=ltiam_a3 > $O/c3.json 2>&1
  J3=$(jget $O/c3.json job)
  $STEP snapshot $O/s7.json
  blocker $db ltiamblk1 "SELECT id FROM mdl_user_enrolments WHERE userid = $A3"
  sleep 1
  $MERGE --execute=$J3 > $O/x3.json 2>&1 & local PHP3=$!
  waitfor 30 jobhas $A3 $B3 '^running inmerge=0 log=[1-9]' || echo "  WARN job 3 did not reach running" >> $O/v_crash3.txt
  sleep 3
  kill -9 $PHP3; wait $PHP3 2>/dev/null
  echo "killed merge process $PHP3 (stalled inside the transaction on a row lock)" > $O/crash3.txt
  unblock $db ltiamblk1
  for i in $(seq 1 20); do $MERGE --recover > $O/rec3.json 2>&1; [ "$(jget $O/rec3.json recover.recoveredaborted)" = 1 ] && break; sleep 1; done
  $STEP snapshot $O/s8.json
  $VERIFY crash-aborted $S $O/s7.json $O/s8.json $O/rec3.json >> $O/v_crash3.txt 2>&1
  H3=$($MERGE --source=$A3 --target=$B3 --preview | hashof)
  $MERGE --source=$A3 --target=$B3 --consent=$H3 --confirm=ltiam_a3 --run > $O/c3b.json 2>&1

  # -------------------------------------------------------------- pair 6: process killed after the commit
  log "[$db] pair 6 (crash after the commit)"
  local A6=$(uid a6) B6=$(uid b6)
  H6=$($MERGE --source=$A6 --target=$B6 --preview | hashof)
  $MERGE --source=$A6 --target=$B6 --consent=$H6 --confirm=ltiam_a6 > $O/c6.json 2>&1
  J6=$(jget $O/c6.json job)
  $STEP snapshot $O/s9.json
  blocker $db ltiamblk1 "SELECT id FROM mdl_user_enrolments WHERE userid = $A6"
  sleep 1
  $MERGE --execute=$J6 > $O/x6.json 2>&1 & local PHP6=$!
  waitfor 30 jobhas $A6 $B6 '^running inmerge=0 log=[1-9]' || echo "  WARN job 6 did not reach running" >> $O/v_crash6.txt
  LOG6=$(php $HERE/step.php jobstate $A6 $B6 | sed 's/.*log=//')
  blocker $db ltiamblk2 "SELECT id FROM mdl_tool_mergeusers WHERE id = $LOG6"
  sleep 1
  unblock $db ltiamblk1
  waitfor 60 jobhas $A6 $B6 '^running inmerge=1' || echo "  WARN job 6 commit not observed" >> $O/v_crash6.txt
  sleep 1
  kill -9 $PHP6; wait $PHP6 2>/dev/null
  echo "killed merge process $PHP6 after the commit (blocked on the update of the tool's log entry $LOG6)" > $O/crash6.txt
  unblock $db ltiamblk2
  for i in $(seq 1 20); do $MERGE --recover > $O/rec6.json 2>&1; [ "$(jget $O/rec6.json recover.recoveredsucceeded)" = 1 ] && break; sleep 1; done
  $STEP snapshot $O/s10.json
  $VERIFY crash-committed $S $O/s9.json $O/s10.json $O/rec6.json >> $O/v_crash6.txt 2>&1

  # -------------------------------------------------------------- concurrency
  log "[$db] concurrency"
  local A4=$(uid a4) B4=$(uid b4) C4=$(uid c4)
  H4=$($MERGE --source=$A4 --target=$B4 --preview | hashof)
  H4B=$($MERGE --source=$B4 --target=$C4 --preview | hashof)
  $MERGE --source=$A4 --target=$B4 --consent=$H4 --confirm=ltiam_a4 > $O/c4a.json 2>&1 &
  $MERGE --source=$B4 --target=$C4 --consent=$H4B --confirm=ltiam_b4 > $O/c4b.json 2>&1 &
  wait
  $VERIFY consent-race $S $O/c4a.json $O/c4b.json > $O/v_consentrace.txt 2>&1
  local A7=$(uid a7) B7=$(uid b7) A8=$(uid a8) B8=$(uid b8)
  H7=$($MERGE --source=$A7 --target=$B7 --preview | hashof)
  H8=$($MERGE --source=$A8 --target=$B8 --preview | hashof)
  J7=$($MERGE --source=$A7 --target=$B7 --consent=$H7 --confirm=ltiam_a7 | php -r 'echo json_decode(stream_get_contents(STDIN),true)["job"];')
  J8=$($MERGE --source=$A8 --target=$B8 --consent=$H8 --confirm=ltiam_a8 | php -r 'echo json_decode(stream_get_contents(STDIN),true)["job"];')
  blocker $db ltiamblk1 "SELECT id FROM mdl_user_enrolments WHERE userid = $A7"
  sleep 1
  $MERGE --execute=$J7 > $O/x7.json 2>&1 & local PHP7=$!
  waitfor 30 jobhas $A7 $B7 '^running' || echo "  WARN job 7 did not reach running" >> $O/v_execrace.txt
  sleep 1
  $MERGE --execute=$J8 > $O/x8.json 2>&1        # another job while job 7 runs
  $MERGE --execute=$J7 > $O/x7b.json 2>&1       # the same job a second time while it runs
  unblock $db ltiamblk1
  wait $PHP7
  $VERIFY exec-race $S $O/x7.json $O/x8.json > $O/v_execrace.txt 2>&1
  $VERIFY exec-race $S $O/x7.json $O/x7b.json >> $O/v_execrace.txt 2>&1
  $MERGE --execute=$J8 > $O/x8b.json 2>&1
  $MERGE --execute=$J7 > $O/x7c.json 2>&1       # idempotent re-run of a finished job
  echo "x8b=$(jget $O/x8b.json status) x7c=$(jget $O/x7c.json status)" >> $O/v_execrace.txt

  # -------------------------------------------------------------- cron path (ad hoc task)
  log "[$db] cron path"
  local A9=$(uid a9) B9=$(uid b9)
  H9=$($MERGE --source=$A9 --target=$B9 --preview | hashof)
  $MERGE --source=$A9 --target=$B9 --consent=$H9 --confirm=ltiam_a9 > $O/c9.json 2>&1
  php admin/cli/adhoc_task.php --execute --ignorelimits > $O/cron.log 2>&1
  php admin/cli/scheduled_task.php --execute='\local_ltiaccountmerge\task\recover_merges' > $O/cron_recover.log 2>&1
  $STEP snapshot $O/sfinal.json

  $STEP render $(uid a2) $(uid b2) $O/merge_page.html > $O/v_render.txt 2>&1 && echo "  OK   merge page parts render with the real tool's data, no missing string" >> $O/v_render.txt \
    || echo "  FAIL merge page parts do not render" >> $O/v_render.txt
  # -------------------------------------------------------------- whole-run invariants
  if [ "$(jget $O/c4a.json status)" = consented ]; then W4="\"$A4\":$B4"; else W4="\"$B4\":$C4"; fi
  echo "{\"$(uid a1)\":$(uid b1),\"$(uid a2)\":$(uid b2),\"$(uid x1)\":$(uid y1),\"$A3\":$B3,\"$A6\":$B6,\"$A7\":$B7,\"$A8\":$B8,\"$A9\":$B9,$W4}" > $O/moves.json
  $VERIFY final $S $O/s0.json $O/sfinal.json $O/moves.json > $O/v_final.txt 2>&1
  log "[$db] done"
}

# ------------------------------------------------------------------ AR-04: real scheduled-task runs
schedule() {
  local db=$1 O=$OUT0/$1/schedule; mkdir -p "$O"
  export LTIAM_ROOT=$PRIV LTI_DB=$db LTI_DBNAME=${NAME}sched_ltiam LTI_BASE=$BASE/sched${TREE}_$db
  cd "$PRIV"
  fresh_site $db $O || return 1
  local SCHED="php $HERE/sched.php" T0=$(( $(date +%s) + 8 ))
  local TASK=(php admin/cli/scheduled_task.php '--execute=\local_ltiaccountmerge\task\apply_modes')
  $SCHED seed $O/seed.json $T0 || return 1
  at() { local t=$(( T0 + $1 )); while [ $(date +%s) -lt $t ]; do sleep 0.2; done; }
  run_task() { "${TASK[@]}" > $O/task_$1.log 2>&1; echo "rc=$?" >> $O/task_$1.log; $SCHED state $O/st_$1.json; }
  probe() { { $SCHED launch sub-l1 t1 $O/seed.json; $SCHED launch sub-l2 t1 $O/seed.json; $SCHED launch sub-new$1 t2 $O/seed.json; } \
    > $O/probe_$1.txt 2>&1; }
  log "[$db] schedule: windows, overlap, manual change"
  at 2;  run_task w0; probe w0
  at 21; run_task w1; probe w1
  at 29; run_task w2
  at 36; $SCHED manual t2 1 $O/seed.json; $SCHED state $O/st_manual.json
  at 37; run_task w3
  at 39; run_task w4
  at 51; run_task w5; probe w5

  log "[$db] schedule: parallel task processes and launches at a boundary"
  local TR=$(( $(date +%s) + 6 ))
  $SCHED policy t1 $TR 2 $O/seed.json
  $SCHED state $O/st_r0.json
  while [ $(date +%s) -lt $TR ]; do sleep 0.05; done
  for i in 1 2 3 4; do ( "${TASK[@]}" > $O/task_r$i.log 2>&1; echo "rc=$?" >> $O/task_r$i.log ) & done
  for i in 1 2 3; do ( $SCHED launch sub-l1 t1 $O/seed.json > $O/probe_r$i.txt 2>&1 ) & done
  wait
  $SCHED state $O/st_r1.json

  log "[$db] schedule: task process killed while writing"
  at 77
  blocker $db ltiamblk3 "SELECT id FROM mdl_enrol_lti_tools WHERE id = $(jget $O/seed.json tools.t6)"
  sleep 1
  "${TASK[@]}" > $O/task_k1.log 2>&1 & local PK=$!
  sleep 5
  $SCHED state $O/st_k0.json
  kill -9 $PK; wait $PK 2>/dev/null
  unblock $db ltiamblk3
  sleep 2
  $SCHED state $O/st_k1.json
  run_task k2

  log "[$db] schedule: a resource that cannot be written"
  at 97
  $SCHED break t7 $O/seed.json
  run_task b1
  $SCHED repair $O/seed.json
  run_task b2

  log "[$db] schedule: daylight saving time (faketime, Europe/Berlin daily 02:30-03:30)"
  : > $O/dst.txt
  # The process clock STARTS at the given instant and runs (a frozen clock makes Moodle's lock and shutdown loops spin
  # forever). Bootstrap takes a few seconds, so "before a boundary" instants are 30 s early; the exact boundary second is
  # covered by PHPUnit (policy_test, mode_applier_test) with an injected clock.
  for spec in 2027-03-27T01:29:30=1 2027-03-27T01:30:00=3 2027-03-27T02:29:30=3 2027-03-27T02:30:00=1 \
      2027-03-28T00:59:30=1 2027-03-28T01:00:00=1 2027-03-28T01:29:30=1 2027-03-28T01:30:00=1 2027-03-28T02:00:00=1 \
      2027-03-29T00:29:30=1 2027-03-29T00:30:00=3 2027-03-29T01:29:30=3 2027-03-29T01:30:00=1 \
      2027-10-31T00:29:30=1 2027-10-31T00:30:00=3 2027-10-31T01:59:30=3 2027-10-31T02:29:30=3 2027-10-31T02:30:00=1; do
    local when=${spec%=*} want=${spec#*=}
    TZ=UTC timeout 120 faketime "${when/T/ }" "${TASK[@]}" > $O/dst_task.log 2>&1
    $SCHED state $O/st_dst.json
    echo "$when $want $(php -r '$s=json_decode(file_get_contents($argv[1]),true); echo $s["tools"][$argv[2]][0];' $O/st_dst.json $(jget $O/seed.json tools.t4))" >> $O/dst.txt
  done
  php $HERE/verify.php sched $O/seed.json $O > $OUT0/$db/v_schedule.txt 2>&1
  log "[$db] schedule done"
}

rc=0
for db in "${DBS[@]}"; do
  mkdir -p $OUT0/$db
  case " ${LTIAM_PARTS:-merge schedule} " in *" merge "*) scenario $db > $OUT0/$db.run.log 2>&1;; esac
  case " ${LTIAM_PARTS:-merge schedule} " in *" schedule "*) schedule $db > $OUT0/$db.schedule.log 2>&1;; esac
  f=$(cat $OUT0/$db/v_*.txt 2>/dev/null | grep -c '^  FAIL'); o=$(cat $OUT0/$db/v_*.txt 2>/dev/null | grep -c '^  OK')
  w=$(cat $OUT0/$db/v_*.txt 2>/dev/null | grep -c 'WARN')
  echo "$TREE/$db: $o OK, $f FAIL, $w WARN  ($OUT0/$db)"
  [ "$f" = 0 ] && [ "$o" -gt 0 ] || rc=1
done
exit $rc
