#!/bin/bash
# ---------------------------------------------------------------------
# Envoltorio del entrypoint oficial de Joomla.
# En segundo plano espera a que Joomla termine su instalación
# desatendida y publica la infografía del proyecto en el panel de
# inicio de la Administración (idempotente: no la duplica).
# ---------------------------------------------------------------------
(
  for i in $(seq 1 180); do
    if [ -f /var/www/html/configuration.php ] \
       && curl -fsS -o /dev/null http://127.0.0.1/ \
       && php /parcial2/infografia.php; then
      exit 0
    fi
    sleep 5
  done
  echo "[parcial2] No se pudo instalar la infografía" >&2
) &

exec /entrypoint.sh "$@"
