"""Validate generated QA PDFs with Poppler; visual inspection is still required."""
import json
import re
import subprocess
import sys
from pathlib import Path
from xml.etree import ElementTree

root = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/hardex-quotation-samples')
keys = ('classic modern_blue corporate minimal bold_header elegant construction '
        'hardware_pro compact executive clean_border premium').split()
results = {}
for key in keys:
    results[key] = {}
    for count in (1, 10, 35):
        path = root / f'{key}-{count}.pdf'
        info = subprocess.check_output(['pdfinfo', str(path)], text=True)
        pages = int(re.search(r'Pages:\s+(\d+)', info)[1])
        assert pages == 1 if count == 1 else 2 <= pages <= 5, (path, pages)
        assert '595.28 x 841.89 pts (A4)' in info, path
        text = subprocess.check_output(['pdftotext', '-layout', str(path), '-'], text=True)
        assert sum(text.count(sku) for sku in ('CEM-001', 'NON-012', 'FIX-020')) == count, path
        for value in ('50KG', 'Subtotal', 'Discount', 'Tax', 'Transport', 'Grand Total',
                      'Terms', 'Payment Instructions', 'Prepared By', 'SAMPLE-ACCOUNT'):
            assert value.lower() in text.lower(), (path, value)
        assert {1: '550,000', 10: '2,950,000', 35: '9,620,000'}[count] in text, path
        for index, page in enumerate(text.split('\f')[:pages], 1):
            assert f'Page {index} of {pages}' in page, (path, index, 'footer')
            if any(sku in page for sku in ('CEM-001', 'NON-012', 'FIX-020')):
                assert all(label in page for label in ('Product', 'Unit', 'Qty', 'Discount', 'Line Total')), (path, index, 'header')
        xml = subprocess.check_output(['pdftotext', '-bbox', str(path), '-'])
        tree = ElementTree.fromstring(xml)
        words = tree.findall('.//{http://www.w3.org/1999/xhtml}word')
        for word in words:
            assert 38 <= float(word.attrib['xMin']) < float(word.attrib['xMax']) <= 558, (path, word.text, 'horizontal clipping')
            assert 38 <= float(word.attrib['yMin']) < float(word.attrib['yMax']) <= 819, (path, word.text, 'vertical clipping')
        results[key][str(count)] = pages
print(json.dumps({'pdfs': 36, 'pages': sum(sum(v.values()) for v in results.values()), 'page_counts': results}, indent=2))
