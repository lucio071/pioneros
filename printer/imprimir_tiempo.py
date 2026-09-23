#!/usr/bin/env python3
"""
Imprime etiqueta de tiempo en Niimbot B1
Uso: python3 imprimir_tiempo.py '{"numero":302,...}' [--preview]
"""
import sys
import json
import asyncio
from PIL import Image, ImageDraw, ImageFont
from niimprint import BluetoothTransport, PrinterClient

LABEL_WIDTH = 384  # 50mm a 203dpi
NIIMBOT_MAC = "07:06:04:3F:C4:6F"


def format_tiempo(ms):
    if ms is None or ms == 0:
        return "--:--.--"
    total_s = ms // 1000
    cents = (ms % 1000) // 10
    mins = total_s // 60
    secs = total_s % 60
    return f"{mins:02d}:{secs:02d}.{cents:02d}"


def generar_etiqueta(data):
    img = Image.new('L', (LABEL_WIDTH, 300), 255)
    draw = ImageDraw.Draw(img)

    try:
        font_title = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 22)
        font_big = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 28)
        font_normal = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 18)
        font_small = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 14)
    except Exception:
        font_title = ImageFont.load_default()
        font_big = font_title
        font_normal = font_title
        font_small = font_title

    numero = data.get('numero', '?')
    nombre = data.get('nombre', '')
    categoria = data.get('categoria', '')
    vuelta = data.get('vuelta', 1)
    tramo_a_ms = data.get('tramo_a_ms', 0)
    tramo_b_ms = data.get('tramo_b_ms', 0)
    estacas_a = data.get('estacas_a', 0)
    cintas_a = data.get('cintas_a', 0)
    estacas_b = data.get('estacas_b', 0)
    cintas_b = data.get('cintas_b', 0)
    penal_estaca = data.get('penal_estaca', 5)
    penal_cinta = data.get('penal_cinta', 10)

    penal_a = (estacas_a * penal_estaca + cintas_a * penal_cinta) * 1000
    penal_b = (estacas_b * penal_estaca + cintas_b * penal_cinta) * 1000
    total_a = tramo_a_ms + penal_a
    total_b = tramo_b_ms + penal_b
    total = total_a + total_b

    y = 5

    # Header
    draw.text((10, y), "PIONEROS 4x4", font=font_title, fill=0)
    draw.text((280, y), f"V{vuelta}", font=font_title, fill=0)
    y += 28

    # Numero y nombre
    draw.text((10, y), f"#{numero}", font=font_big, fill=0)
    draw.text((100, y + 5), nombre[:20], font=font_normal, fill=0)
    y += 35

    # Linea
    draw.line([(10, y), (LABEL_WIDTH - 10, y)], fill=0, width=2)
    y += 8

    # Pista A
    draw.text((10, y), "Pista A:", font=font_normal, fill=0)
    draw.text((200, y), format_tiempo(tramo_a_ms), font=font_normal, fill=0)
    y += 22
    if estacas_a or cintas_a:
        txt = f"  E:{estacas_a} C:{cintas_a} (+{format_tiempo(penal_a)})"
        draw.text((10, y), txt, font=font_small, fill=0)
        y += 18

    # Pista B
    draw.text((10, y), "Pista B:", font=font_normal, fill=0)
    draw.text((200, y), format_tiempo(tramo_b_ms), font=font_normal, fill=0)
    y += 22
    if estacas_b or cintas_b:
        txt = f"  E:{estacas_b} C:{cintas_b} (+{format_tiempo(penal_b)})"
        draw.text((10, y), txt, font=font_small, fill=0)
        y += 18

    # Linea
    draw.line([(10, y), (LABEL_WIDTH - 10, y)], fill=0, width=2)
    y += 8

    # Total
    draw.text((10, y), "TOTAL:", font=font_big, fill=0)
    draw.text((160, y), format_tiempo(total), font=font_big, fill=0)
    y += 35

    # Categoria
    draw.text((10, y), categoria, font=font_small, fill=0)
    y += 20

    # Recortar al alto real y convertir a 1-bit para impresion
    img = img.crop((0, 0, LABEL_WIDTH, y + 5))
    img = img.convert('1')
    return img


async def imprimir(data):
    img = generar_etiqueta(data)
    img.save('/tmp/etiqueta_preview.png')

    try:
        transport = BluetoothTransport(NIIMBOT_MAC)
        printer = PrinterClient(transport)
        printer.print_image(img)
        print("OK: etiqueta impresa")
    except Exception as e:
        import traceback
        print(f"ERROR: {e}")
        traceback.print_exc()
        print("Preview guardada en /tmp/etiqueta_preview.png")


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Uso: python3 imprimir_tiempo.py '{json}' [--preview]")
        sys.exit(1)

    data = json.loads(sys.argv[1])

    if '--preview' in sys.argv:
        img = generar_etiqueta(data)
        img.save('/tmp/etiqueta_preview.png')
        print("Preview: /tmp/etiqueta_preview.png")
    else:
        asyncio.run(imprimir(data))
