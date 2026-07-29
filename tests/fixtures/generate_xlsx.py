import csv, datetime
from openpyxl import Workbook

def parse_date(s):
    try: return datetime.datetime.strptime(s, "%d-%m-%Y")
    except Exception: return None

def gen(csv_path, xlsx_path):
    rows = list(csv.reader(open(csv_path, newline='')))
    title, drange, header = rows[0], rows[1], rows[2]
    data = rows[3:]
    date_cols = {i for i,h in enumerate(header) if 'date' in h.lower()}
    flt_cols  = {i for i,h in enumerate(header) if 'flight' in h.lower()}
    wb = Workbook(); ws = wb.active
    ws.append([title[0]]); ws.append([drange[0]]); ws.append(header)
    for r in data:
        out=[]
        for i,v in enumerate(r):
            if i in date_cols and parse_date(v): out.append(parse_date(v))   # real Excel date
            elif i in flt_cols and v.strip().lstrip('-').isdigit(): out.append(int(v))
            else: out.append(v)
        ws.append(out)
    wb.save(xlsx_path)

gen("tests/fixtures/flight_count_inc.csv","tests/fixtures/flight_count_inc.xlsx")
gen("tests/fixtures/flight_count_ltd.csv","tests/fixtures/flight_count_ltd.xlsx")
print("generated xlsx")
