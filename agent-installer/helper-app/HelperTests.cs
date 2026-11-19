// Alat uji (BUKAN bagian produk): merender jendela ke PNG lewat DrawToBitmap (tanpa tangkapan layar penuh, tanpa
// memunculkan apa pun di layar pengguna) dan menguji parsing jawaban server. Dikompilasi dengan
// /main:Akp.SimaAgentHelper.UiShot atau /main:Akp.SimaAgentHelper.ParseTest.
using System;
using System.Drawing;
using System.IO;
using System.Windows.Forms;

namespace Akp.SimaAgentHelper
{
    static class UiShot
    {
        static void Pump(int ms)
        {
            DateTime end = DateTime.Now.AddMilliseconds(ms);
            while (DateTime.Now < end) { Application.DoEvents(); System.Threading.Thread.Sleep(15); }
        }

        static void Save(Form f, string path)
        {
            using (Bitmap b = new Bitmap(f.Width, f.Height))
            {
                f.DrawToBitmap(b, new Rectangle(0, 0, f.Width, f.Height));
                b.Save(path, System.Drawing.Imaging.ImageFormat.Png);
            }
        }

        [STAThread]
        static void Main(string[] args)
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            string dir = args[0];

            Announcement[] anns = new Announcement[]
            {
                new Announcement { Id = 1, Severity = "info", RequireAck = false, Title = "Jadwal pemeliharaan jaringan akhir pekan", Body = "Jaringan kantor akan dipelihara Sabtu pukul 22.00 sampai Minggu 04.00.\nSelama waktu itu Wi-Fi dan VPN tidak tersedia. Mohon simpan pekerjaan Anda sebelum pulang pada hari Jumat." },
                new Announcement { Id = 2, Severity = "important", RequireAck = true, Title = "Kampanye keamanan: waspada email phishing", Body = "Minggu ini banyak email yang mengaku dari bagian keuangan dan meminta Anda membuka lampiran atau mengisi kata sandi.\n\n1. Jangan klik tautan dari pengirim yang tidak dikenal.\n2. Laporkan email mencurigakan ke it@akp.co.id.\n3. Jangan pernah membagikan kata sandi, bahkan kepada IT." },
                new Announcement { Id = 3, Severity = "urgent", RequireAck = true, Title = "Segera restart laptop Anda", Body = "Pembaruan keamanan kritis sudah terpasang dan menunggu restart. Mohon restart sebelum pukul 15.00 hari ini." },
            };
            foreach (Announcement a in anns)
            {
                using (AnnouncementForm f = new AnnouncementForm(a))
                {
                    f.StartPosition = FormStartPosition.Manual;
                    f.Location = new Point(-4000, -4000);
                    f.Show();
                    Pump(300);
                    Save(f, Path.Combine(dir, "ann-" + a.Severity + ".png"));
                }
            }

            using (ChatForm c = new ChatForm("Eddy Adha"))
            {
                c.StartPosition = FormStartPosition.Manual;
                c.Location = new Point(-4000, -4000);
                c.Show();
                Pump(200);
                DateTime t = new DateTime(2026, 10, 26, 10, 12, 0);
                c.AddMessage(new ChatMsg { Id = 1, Sender = "it", Name = "Eddy Adha", At = t, Body = "Selamat pagi, Pak. Ini dari IT AKP. Kami melihat laptop Bapak belum restart setelah update keamanan." });
                c.AddMessage(new ChatMsg { Id = 2, Sender = "user", At = t.AddMinutes(1), Body = "Pagi. Oh iya, saya lupa. Boleh nanti jam 1 siang?" });
                c.AddMessage(new ChatMsg { Id = 3, Sender = "it", Name = "Eddy Adha", At = t.AddMinutes(2), Body = "Boleh, Pak. Mohon pastikan pekerjaan sudah tersimpan ya. Terima kasih." });
                c.AddMessage(new ChatMsg { Id = 4, Sender = "user", At = t.AddMinutes(3), Body = "Siap, terima kasih." });
                Pump(300);
                Save(c, Path.Combine(dir, "chat-open.png"));

                c.SetEnded("admin");
                Pump(200);
                Save(c, Path.Combine(dir, "chat-ended.png"));
            }
        }
    }

    static class ParseTest
    {
        static int Main(string[] args)
        {
            ApiClient api = new ApiClient();
            PollResult r = api.ParsePoll(File.ReadAllText(args[0]));
            Console.WriteLine("poll_seconds=" + r.PollSeconds);
            Console.WriteLine("announcements=" + r.Announcements.Count);
            foreach (Announcement a in r.Announcements)
                Console.WriteLine("  #" + a.Id + " [" + a.Severity + "] ack=" + a.RequireAck + " | " + a.Title + " | " + a.Body.Replace("\n", "\\n"));
            Console.WriteLine("session=" + r.SessionId + " status=" + r.SessionStatus + " reason=" + r.CloseReason + " by=" + r.StartedBy);
            Console.WriteLine("messages=" + r.Messages.Count);
            foreach (ChatMsg m in r.Messages)
                Console.WriteLine("  #" + m.Id + " " + m.Sender + " (" + m.Name + ") " + m.At.ToString("HH:mm") + " | " + m.Body);
            return 0;
        }
    }
}
