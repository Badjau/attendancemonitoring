using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

namespace Sample;

public static class BitmapFormat
{
    public static void GetBitmap(byte[] buffer, int width, int height, ref MemoryStream stream)
    {
        var colorTable = new ColorPaletteEntries();
        var bmp = new Bitmap(width, height, PixelFormat.Format8bppIndexed);
        var palette = bmp.Palette;

        for (var i = 0; i < 256; i++)
        {
            palette.Entries[i] = Color.FromArgb(i, i, i);
        }

        bmp.Palette = palette;

        var rect = new Rectangle(0, 0, width, height);
        var bmpData = bmp.LockBits(rect, ImageLockMode.WriteOnly, PixelFormat.Format8bppIndexed);
        Marshal.Copy(buffer, 0, bmpData.Scan0, width * height);
        bmp.UnlockBits(bmpData);
        bmp.Save(stream, ImageFormat.Bmp);
        bmp.Dispose();
    }

    private sealed class ColorPaletteEntries
    {
    }
}
