echo @off

del Kumo-Desu-Ga-Nani-Ka.epub
"C:\Program Files (x86)\7-Zip\7z.exe" a -tzip -mx=9 -mmt=on Kumo-Desu-Ga-Nani-Ka.epub META-INF/ content.opf images/ mimetype style/ text/ toc.ncx

