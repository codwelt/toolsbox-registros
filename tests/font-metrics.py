"""Extrae métricas de la fuente estática con Python estándar, solo en desarrollo."""
import json, struct, hashlib
from pathlib import Path
root=Path(__file__).resolve().parents[1]
data=(root/'assets/fonts/DejaVuSans.ttf').read_bytes()
def u16(offset): return struct.unpack_from('>H',data,offset)[0]
def i16(offset): return struct.unpack_from('>h',data,offset)[0]
def u32(offset): return struct.unpack_from('>I',data,offset)[0]
tables={}
for index in range(u16(4)):
    offset=12+index*16
    tables[data[offset:offset+4].decode('ascii')]=u32(offset+8)
units=u16(tables['head']+18)
metrics=u16(tables['hhea']+34)
advances=[u16(tables['hmtx']+index*4) for index in range(metrics)]
cmap=tables['cmap'];chosen=None
for index in range(u16(cmap+2)):
    item=cmap+4+index*8;sub=cmap+u32(item+4)
    if u16(sub)==12 and u16(item) in (0,3):chosen=sub;break
assert chosen is not None,'La fuente debe tener cmap Unicode formato 12.'
chars={}
for index in range(u32(chosen+12)):
    item=chosen+16+index*12;start,end,glyph=u32(item),u32(item+4),u32(item+8)
    for cp in range(start,end+1):
        gid=glyph+cp-start
        chars[str(cp)]=[gid,round(advances[min(gid,metrics-1)]*1000/units)]
out={'sha256':hashlib.sha256(data).hexdigest(),'bbox':[round(i16(tables['head']+offset)*1000/units) for offset in (36,38,40,42)],'ascent':round(i16(tables['hhea']+4)*1000/units),'descent':round(i16(tables['hhea']+6)*1000/units),'chars':chars}
(root/'assets/fonts/DejaVuSans-metrics.json').write_text(json.dumps(out,separators=(',',':'))+'\n')
print('Métricas Unicode generadas:',len(chars),'caracteres')
