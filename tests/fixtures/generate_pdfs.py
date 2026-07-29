import csv, sys
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4, landscape

def load(csv_path):
    rows=[]
    with open(csv_path, newline='') as f:
        for r in csv.reader(f):
            rows.append(r)
    # rows[0]=title, rows[1]=date range, rows[2]=header, rest data incl ∑
    title=rows[0][0]; drange=rows[1][0]; header=rows[2]
    data=[r for r in rows[3:]]
    return title, drange, header, data

def gen(csv_path, pdf_path):
    title,drange,header,data=load(csv_path)
    ncol=len(header)
    # column width in chars = max cell length + 2 padding
    widths=[0]*ncol
    for row in [header]+data:
        for i in range(ncol):
            if i < len(row):
                widths[i]=max(widths[i], len(row[i]))
    widths=[w+3 for w in widths]
    fs=8.0; cw=fs*0.6  # Courier char width
    xs=[]; x=30
    for w in widths:
        xs.append(x); x += w*cw
    c=canvas.Canvas(pdf_path, pagesize=landscape(A4))
    c.setFont("Courier", fs)
    y=550
    c.drawString(30,y,title); y-=14
    c.drawString(30,y,drange); y-=18
    def draw(row,yy):
        for i,cell in enumerate(row):
            c.drawString(xs[i],yy,cell)
    draw(header,y); y-=14
    for row in data:
        draw(row,y); y-=13
    c.save()

gen("tests/fixtures/flight_count_inc.csv","/tmp/leon_inc.pdf")
gen("tests/fixtures/flight_count_ltd.csv","/tmp/leon_ltd.pdf")
print("generated")
