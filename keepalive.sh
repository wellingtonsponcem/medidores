#!/bin/bash
# Mantém o projeto Supabase ativo fazendo uma consulta leve a cada execução

SUPABASE_URL="https://jmdlraaprcztshkfyeud.supabase.co"
SUPABASE_KEY="sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC"
LOG="$HOME/Library/Logs/supabase-keepalive.log"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Executando keepalive..." >> "$LOG"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  "$SUPABASE_URL/rest/v1/usuarios?select=id&limit=1" \
  -H "apikey: $SUPABASE_KEY" \
  -H "Authorization: Bearer $SUPABASE_KEY")

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Status HTTP: $STATUS" >> "$LOG"
