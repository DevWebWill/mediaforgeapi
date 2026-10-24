#!/bin/sh
# Regenera las fixtures multimedia de las pruebas (archivos pequeños y deterministas).
# Uso: docker compose exec app sh tests/Fixtures/media/generate.sh
set -eu
cd "$(dirname "$0")"

ffmpeg -nostdin -hide_banner -loglevel error -y \
  -f lavfi -i testsrc=size=320x240:rate=25:duration=2 \
  -f lavfi -i sine=frequency=440:duration=2 \
  -c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac -b:a 64k -shortest -movflags +faststart sample.mp4

ffmpeg -nostdin -hide_banner -loglevel error -y \
  -f lavfi -i testsrc=size=240x320:rate=25:duration=2 \
  -c:v libx264 -preset ultrafast -pix_fmt yuv420p -an -movflags +faststart sample-noaudio.mp4

ffmpeg -nostdin -hide_banner -loglevel error -y \
  -f lavfi -i sine=frequency=440:duration=2 -c:a libmp3lame -b:a 64k sample.mp3

# Contenedor MP4 truncado: firma válida pero datos corruptos
head -c 4096 sample.mp4 > corrupt.mp4

# Texto con extensión de vídeo
printf '<?php echo "not a video"; ?>\n' > fake.mp4
