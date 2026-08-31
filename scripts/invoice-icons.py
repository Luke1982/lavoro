import base64, subprocess, os, tempfile

SHAPES = {
 'pin':'<path d="M12 21s7-6.4 7-11.2A7 7 0 0 0 5 9.8C5 14.6 12 21 12 21z"/><circle cx="12" cy="9.6" r="2.6"/>',
 'file':'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
 'phone':'<path d="M6.5 3h3l1.5 4-2 1.4a12 12 0 0 0 5.6 5.6L16 12l4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.2 2 2 0 0 1 6.5 3z"/>',
 'mail':'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5 12 12.5l8.5-6"/>',
 'calendar':'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
 'card':'<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19"/><path d="M6.5 15h4"/>',
 'refresh':'<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20 4v4h-4"/>',
 'coin':'<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v9M14.4 9.6a2.6 2.6 0 0 0-4.8 1.2c0 2.4 4.8 1.2 4.8 3.6a2.6 2.6 0 0 1-4.8 1.2"/>',
 'globe':'<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5a13 13 0 0 1 0 17 13 13 0 0 1 0-17z"/>',
 'users':'<path d="M15.5 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H6.4A3.4 3.4 0 0 0 3 18.4V20"/><circle cx="9.2" cy="7.6" r="3.6"/><path d="M21 20v-1.6a3.4 3.4 0 0 0-2.6-3.3"/><path d="M15.6 4.2a3.4 3.4 0 0 1 0 6.6"/>',
 'box':'<path d="M20.5 7.5 12 3 3.5 7.5v9L12 21l8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/>',
 'drive':'<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
 'tag':'<path d="M20.6 13.4 12.4 21.6a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4a2 2 0 0 1 2-2h9a2 2 0 0 1 1.4.6l6.4 6.4a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.4"/>',
}

OUT = 'public/img/invoice'
PIXELS_PER_POINT = 4          # scherp genoeg om op papier niet te rafelen

def svg(name, colour, circle=None):
    glyph = ('<g fill="none" stroke="%s" stroke-width="1.7" stroke-linecap="round" '
             'stroke-linejoin="round">%s</g>' % (colour, SHAPES[name]))
    if circle:
        inner = 0.5
        off = 12 * (1 - inner)
        glyph = ('<circle cx="12" cy="12" r="12" fill="%s"/>'
                 '<g transform="translate(%s %s) scale(%s)">%s</g>' % (circle, off, off, inner, glyph))
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">%s</svg>' % glyph

# (bestandsnaam, icoon, kleur, cirkelkleur, maat in punten)
WANTED = [
    ('pin',       'pin',      '#2563eb', None,      11),
    ('file',      'file',     '#2563eb', None,      11),
    ('phone',     'phone',    '#2563eb', None,      11),
    ('mail',      'mail',     '#2563eb', None,      11),
    ('globe-grey','globe',    '#64748b', None,      11),
    ('phone-grey','phone',    '#64748b', None,      11),
    ('mail-grey', 'mail',     '#64748b', None,      11),
    ('calendar-bubble', 'calendar', '#2563eb', '#ffffff', 36),
    ('card-bubble',     'card',     '#2563eb', '#ffffff', 39),
    ('calendar-dot',    'calendar', '#2563eb', '#eff6ff', 22),
    ('refresh-dot',     'refresh',  '#2563eb', '#eff6ff', 22),
    ('coin-dot',        'coin',     '#2563eb', '#eff6ff', 22),
    ('file-dot',        'file',     '#2563eb', '#eff6ff', 22),
    ('users-dot',       'users',    '#2563eb', '#eff6ff', 22),
    ('box-dot',         'box',      '#2563eb', '#eff6ff', 22),
    ('drive-dot',       'drive',    '#2563eb', '#eff6ff', 22),
    ('tag-dot',         'tag',      '#2563eb', '#eff6ff', 22),
]

for filename, name, colour, circle, points in WANTED:
    px = points * PIXELS_PER_POINT
    with tempfile.NamedTemporaryFile('w', suffix='.svg', delete=False) as f:
        f.write(svg(name, colour, circle))
        tmp = f.name
    target = os.path.join(OUT, filename + '.png')
    subprocess.run(['inkscape', tmp, '--export-type=png', '--export-filename=' + target,
                    '--export-width=%d' % px, '--export-height=%d' % px,
                    '--export-background-opacity=0'], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.unlink(tmp)
    print(filename, '->', px, 'px voor', points, 'pt')
