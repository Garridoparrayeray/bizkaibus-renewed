Add-Type -AssemblyName System.Drawing

$width = 512
$height = 512
$bitmap = New-Object System.Drawing.Bitmap($width, $height)
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)

$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit

$graphics.Clear([System.Drawing.Color]::White)

$bFont = New-Object System.Drawing.Font('Arial', 250, [System.Drawing.FontStyle]::Bold)
$greenBrush = New-Object System.Drawing.SolidBrush([System.Drawing.ColorTranslator]::FromHtml('#085c36'))
$redBrush = New-Object System.Drawing.SolidBrush([System.Drawing.ColorTranslator]::FromHtml('#d1152a'))

$graphics.DrawString('B', $bFont, $greenBrush, 50.0, 30.0)

$plusCenterX = 390.0
$plusCenterY = 110.0 # MOVED UP from 160.0
$plusSize = 110.0
$plusThickness = 38.0 

$rectH = New-Object System.Drawing.RectangleF(($plusCenterX - $plusSize/2), ($plusCenterY - $plusThickness/2), $plusSize, $plusThickness)
$rectV = New-Object System.Drawing.RectangleF(($plusCenterX - $plusThickness/2), ($plusCenterY - $plusSize/2), $plusThickness, $plusSize)

$graphics.TranslateTransform($plusCenterX, $plusCenterY)
$graphics.RotateTransform(-8)
$graphics.TranslateTransform(-$plusCenterX, -$plusCenterY)

$graphics.FillRectangle($redBrush, $rectH)
$graphics.FillRectangle($redBrush, $rectV)

$graphics.ResetTransform()

$bitmap.Save('C:\Users\YGarrido\Documents\bizkaibus-renewed\logo_bideplus_new.png', [System.Drawing.Imaging.ImageFormat]::Png)

$graphics.Dispose()
$bitmap.Dispose()
