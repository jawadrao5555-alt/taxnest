#!/bin/bash
LOG="/home/runner/workspace/fbr_retry_log.txt"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] === BASH RETRY LOOP STARTED ===" >> "$LOG"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] 4 attempts, 30 min apart" >> "$LOG"

for i in 1 2 3 4; do
    if [ $i -gt 1 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Sleeping 30 minutes before attempt $i..." >> "$LOG"
        sleep 1800
    fi
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Running attempt $i..." >> "$LOG"
    php /home/runner/workspace/fbr_single_try.php $i 2>&1
    EXIT_CODE=$?
    
    if [ $EXIT_CODE -eq 0 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] SUCCESS! Stopping retries." >> "$LOG"
        break
    elif [ $EXIT_CODE -eq 2 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Validation error - will still retry." >> "$LOG"
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rate limited or error - will retry." >> "$LOG"
    fi
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === RETRY LOOP FINISHED ===" >> "$LOG"
