// Alat uji tampilan (BUKAN bagian produk): merender jendela ke PNG dari dalam proses ini lewat DrawToBitmap,
// tanpa tangkapan layar penuh dan tanpa mengirim ketikan ke jendela lain. Dikompilasi dengan /main:Akp.SimaAgentStatus.UiShot.
using System;
using System.Drawing;
using System.Windows.Forms;

namespace Akp.SimaAgentStatus
{
    static class UiShot
    {
        [STAThread]
        static void Main(string[] args)
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            string outDir = args[0];
            using (Form f = new LoginForm())
            {
                f.StartPosition = FormStartPosition.Manual;
                f.Location = new Point(-3000, -3000);
                f.Show();
                Pump(500);
                Save(f, outDir + "\\login.png");
            }
            using (Form f = new MainForm())
            {
                f.StartPosition = FormStartPosition.Manual;
                f.Location = new Point(-3000, -3000);
                f.Show();
                Pump(6000);
                Save(f, outDir + "\\main.png");
            }
        }

        static void Pump(int ms)
        {
            DateTime end = DateTime.Now.AddMilliseconds(ms);
            while (DateTime.Now < end) { Application.DoEvents(); System.Threading.Thread.Sleep(20); }
        }

        static void Save(Form f, string path)
        {
            using (Bitmap b = new Bitmap(f.Width, f.Height))
            {
                f.DrawToBitmap(b, new Rectangle(0, 0, f.Width, f.Height));
                b.Save(path, System.Drawing.Imaging.ImageFormat.Png);
            }
        }
    }
}
