import ast
import json
import sys
import zipfile
from pathlib import PurePosixPath, PureWindowsPath

path=sys.argv[1]
python_count=json_count=0
with zipfile.ZipFile(path) as archive:
    bad=archive.testzip()
    if bad: raise RuntimeError(f'CRC failed: {bad}')
    seen=set()
    for member in archive.infolist():
        name=PurePosixPath(member.filename)
        if name.is_absolute() or PureWindowsPath(member.filename).drive or '..' in name.parts or '\\' in member.filename or ':' in member.filename:
            raise RuntimeError('Unsafe archive path')
        folded=member.filename.casefold()
        if folded in seen or (member.external_attr >> 16) & 0o170000 == 0o120000:
            raise RuntimeError('Duplicate archive path or symbolic link')
        seen.add(folded)
        if any(part in {'data','photos','logs','__pycache__','updates','firmware_backups','.git','.venv'} for part in name.parts):
            raise RuntimeError('Runtime or private directory in release')
        if name.name=='settings.json' or name.name.startswith('.env') or name.suffix in {'.pyc','.bak','.backup','.log'}:
            raise RuntimeError('Private/runtime file in release')
        if name.suffix=='.py':
            ast.parse(archive.read(member).decode('utf-8-sig'),filename=member.filename)
            python_count+=1
        elif name.suffix=='.json':
            json.loads(archive.read(member).decode('utf-8-sig'))
            json_count+=1
print(f'ZIP CRC, paths, exclusions, Python AST ({python_count}), JSON ({json_count}): PASS')
