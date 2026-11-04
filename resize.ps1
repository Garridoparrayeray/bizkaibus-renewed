Add-Type -AssemblyName System.Drawing
$img = [System.Drawing.Image]::FromFile('c:\Users\ygarrido\Documents\bide+\bizkaibus_metro+\icons-euskotren\icon-512.png')
Write-Host $img.Width 'x' $img.Height

$bmp192 = New-Object System.Drawing.Bitmap 192, 192
$g192 = [System.Drawing.Graphics]::FromImage($bmp192)
$g192.DrawImage($img, 0, 0, 192, 192)
$bmp192.Save('c:\Users\ygarrido\Documents\bide+\bizkaibus_metro+\icons-euskotren\icon-192.png')
$g192.Dispose()
$bmp192.Dispose()

$bmp180 = New-Object System.Drawing.Bitmap 180, 180
$g180 = [System.Drawing.Graphics]::FromImage($bmp180)
$g180.DrawImage($img, 0, 0, 180, 180)
$bmp180.Save('c:\Users\ygarrido\Documents\bide+\bizkaibus_metro+\icons-euskotren\apple-touch-icon.png')
$g180.Dispose()
$bmp180.Dispose()
$img.Dispose()

