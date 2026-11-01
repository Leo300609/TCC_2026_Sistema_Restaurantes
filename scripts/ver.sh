#!/bin/bash
# uso: ver.sh funcao arquivo  -> mostra a função com números de linha
s=$(grep -n "FUNCTION $1(" "$2" | head -1 | cut -d: -f1)
e=$(awk -v s="$s" 'NR>s && /^\$fn\$;/ {print NR; exit}' "$2")
sed -n "${s},${e}p" "$2" | nl -ba -v"$s" -w5 -s'  '
