// SIMA Agent Status - aplikasi desktop untuk memeriksa status SIMA Asset Agent di perangkat ini.
// Dikompilasi dengan csc bawaan Windows (.NET Framework 4, C# 5): lihat build.ps1.
// Membaca config.json + agent.log di folder yang sama dengan .exe ini, dan status Scheduled Task "SIMA Asset Agent".
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Globalization;
using System.IO;
using System.Net;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Forms;

namespace Akp.SimaAgentStatus
{
    static class Theme
    {
        public static readonly Color Navy = ColorTranslator.FromHtml("#27386B");
        public static readonly Color Sky = ColorTranslator.FromHtml("#4FA6DC");
        public static readonly Color Teal = ColorTranslator.FromHtml("#66BFB0");
        public static readonly Color Bg = ColorTranslator.FromHtml("#F3F6FA");
        public static readonly Color Line = ColorTranslator.FromHtml("#DDE3EC");
        public static readonly Color Muted = ColorTranslator.FromHtml("#667085");
        public static readonly Color Ink = ColorTranslator.FromHtml("#1D2939");

        public static Image Logo()
        {
            using (Stream s = Assembly.GetExecutingAssembly().GetManifestResourceStream("akp-logo.png"))
            {
                return s == null ? null : Image.FromStream(s);
            }
        }
    }

    // Kata sandi aplikasi TIDAK disimpan sebagai teks: hanya hash PBKDF2 (SHA-1, 100.000 putaran, salt tetap).
    // Ini pagar ringan agar pengguna biasa tidak membuka status/log agent, bukan pengaman kriptografis penuh:
    // siapa pun yang admin di laptop ini tetap bisa membaca agent.log dan config.json langsung.
    static class Auth
    {
        static readonly byte[] Salt = new byte[] { 0x53, 0x49, 0x4D, 0x41, 0x2D, 0x41, 0x4B, 0x50, 0x9C, 0x3E, 0x71, 0xD2, 0x08, 0xB5, 0x6A, 0xF4 };
        const int Iterations = 100000;
        const string ExpectedHex = "6710e4be6154189eb192b21aa58317e1990ada33cdf2a6e56a54d8568fca2609";

        public static bool Verify(string password)
        {
            byte[] got;
            using (Rfc2898DeriveBytes k = new Rfc2898DeriveBytes(password ?? "", Salt, Iterations))
            {
                got = k.GetBytes(32);
            }
            StringBuilder sb = new StringBuilder();
            foreach (byte b in got) sb.Append(b.ToString("x2"));
            string a = sb.ToString();
            int diff = a.Length ^ ExpectedHex.Length;
            for (int i = 0; i < a.Length && i < ExpectedHex.Length; i++) diff |= a[i] ^ ExpectedHex[i];
            return diff == 0;
        }
    }

    static class Program
    {
        [STAThread]
        static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            bool ok;
            using (LoginForm login = new LoginForm())
            {
                ok = login.ShowDialog() == DialogResult.OK;
            }
            if (ok) Application.Run(new MainForm());
        }
    }

    class LoginForm : Form
    {
        TextBox pass = new TextBox();
        Label err = new Label();
        Button go = new Button();
        System.Windows.Forms.Timer lockTimer = new System.Windows.Forms.Timer();
        int fails;
        int lockLeft;

        public LoginForm()
        {
            AutoScaleDimensions = new SizeF(96F, 96F);
            AutoScaleMode = AutoScaleMode.Dpi;
            Text = "SIMA Agent Status";
            ClientSize = new Size(420, 400);
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;
            MinimizeBox = false;
            StartPosition = FormStartPosition.CenterScreen;
            BackColor = Color.White;
            Font = new Font("Segoe UI", 9.5f);
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }

            Panel stripe = new Panel();
            stripe.Dock = DockStyle.Top;
            stripe.Height = 6;
            stripe.BackColor = Theme.Navy;
            Controls.Add(stripe);

            PictureBox logo = new PictureBox();
            logo.Image = Theme.Logo();
            logo.SizeMode = PictureBoxSizeMode.Zoom;
            logo.Size = new Size(240, 67);
            logo.Location = new Point(90, 40);
            Controls.Add(logo);

            Label title = new Label();
            title.Text = "SIMA Asset Agent";
            title.Font = new Font("Segoe UI Semibold", 16f);
            title.ForeColor = Theme.Navy;
            title.TextAlign = ContentAlignment.MiddleCenter;
            title.SetBounds(0, 125, 420, 40);
            Controls.Add(title);

            Label sub = new Label();
            sub.Text = "Masukkan password untuk melihat status agent";
            sub.ForeColor = Theme.Muted;
            sub.TextAlign = ContentAlignment.MiddleCenter;
            sub.SetBounds(20, 168, 380, 24);
            Controls.Add(sub);

            pass.UseSystemPasswordChar = true;
            pass.Font = new Font("Segoe UI", 11f);
            pass.SetBounds(60, 205, 300, 30);
            Controls.Add(pass);

            err.ForeColor = ColorTranslator.FromHtml("#B42318");
            err.TextAlign = ContentAlignment.MiddleCenter;
            err.SetBounds(20, 240, 380, 22);
            Controls.Add(err);

            go.Text = "Masuk";
            go.FlatStyle = FlatStyle.Flat;
            go.FlatAppearance.BorderSize = 0;
            go.BackColor = Theme.Navy;
            go.ForeColor = Color.White;
            go.Font = new Font("Segoe UI Semibold", 10.5f);
            go.Cursor = Cursors.Hand;
            go.SetBounds(60, 275, 300, 40);
            go.Click += delegate { TryLogin(); };
            Controls.Add(go);
            AcceptButton = go;

            Label foot = new Label();
            foot.Text = "PT Adhi Kartiko Pratama Tbk  -  IT";
            foot.ForeColor = Theme.Muted;
            foot.Font = new Font("Segoe UI", 8.5f);
            foot.TextAlign = ContentAlignment.MiddleCenter;
            foot.SetBounds(0, 355, 420, 22);
            Controls.Add(foot);

            lockTimer.Interval = 1000;
            lockTimer.Tick += delegate
            {
                lockLeft--;
                if (lockLeft <= 0)
                {
                    lockTimer.Stop();
                    fails = 0;
                    go.Enabled = true;
                    pass.Enabled = true;
                    err.Text = "";
                    pass.Focus();
                }
                else err.Text = "Terlalu banyak percobaan. Coba lagi dalam " + lockLeft + " detik.";
            };
            Shown += delegate { pass.Focus(); };
        }

        void TryLogin()
        {
            if (Auth.Verify(pass.Text))
            {
                DialogResult = DialogResult.OK;
                Close();
                return;
            }
            fails++;
            pass.Clear();
            if (fails >= 5)
            {
                lockLeft = 30;
                go.Enabled = false;
                pass.Enabled = false;
                err.Text = "Terlalu banyak percobaan. Coba lagi dalam 30 detik.";
                lockTimer.Start();
            }
            else
            {
                err.Text = "Password salah (" + fails + " dari 5).";
                pass.Focus();
            }
        }
    }

    class AgentInfo
    {
        public string AppDir;
        public bool ConfigFound;
        public string SimaUrl = "";
        public bool HasToken;
        public bool LogFound;
        public List<string> LogTail = new List<string>();
        public DateTime? LastOk;
        public string LastOkHost = "";
        public string LastOkIp = "";
        public string LastLine = "";
        public bool LastIsError;
        public string LastError = "";
        public bool TaskExists;
        public string TaskState = "";
        public string TaskLastRun = "";
        public string TaskNextRun = "";
        public string TaskResult = "";
        public int IntervalMinutes = 5;
        public int AgentVersion;
        public string LastUpdate = "";
    }

    class MainForm : Form
    {
        const string TaskName = "SIMA Asset Agent";
        readonly string appDir = AppDomain.CurrentDomain.BaseDirectory;

        Panel banner = new Panel();
        Label bannerTitle = new Label();
        Label bannerSub = new Label();
        Dictionary<string, Label> values = new Dictionary<string, Label>();
        TextBox logBox = new TextBox();
        Label statusBar = new Label();
        Button btnRefresh, btnSend, btnTest, btnLog;
        System.Windows.Forms.Timer auto = new System.Windows.Forms.Timer();
        AgentInfo current = new AgentInfo();
        bool busy;

        public MainForm()
        {
            AutoScaleDimensions = new SizeF(96F, 96F);
            AutoScaleMode = AutoScaleMode.Dpi;
            Text = "SIMA Agent Status - AKP";
            ClientSize = new Size(860, 880);
            MinimumSize = new Size(780, 840);
            StartPosition = FormStartPosition.CenterScreen;
            BackColor = Theme.Bg;
            Font = new Font("Segoe UI", 9.5f);
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }

            // Susunan dari bawah ke atas supaya Dock bekerja benar.
            statusBar.Dock = DockStyle.Bottom;
            statusBar.Height = 30;
            statusBar.Padding = new Padding(20, 0, 0, 0);
            statusBar.TextAlign = ContentAlignment.MiddleLeft;
            statusBar.ForeColor = Theme.Muted;
            statusBar.Font = new Font("Segoe UI", 8.5f);
            Controls.Add(statusBar);

            Panel body = new Panel();
            body.Dock = DockStyle.Fill;
            body.Padding = new Padding(20, 16, 20, 8);
            Controls.Add(body);

            BuildBody(body);

            Panel header = new Panel();
            header.Dock = DockStyle.Top;
            header.Height = 92;
            header.BackColor = Color.White;
            Controls.Add(header);
            BuildHeader(header);

            Panel stripe = new Panel();
            stripe.Dock = DockStyle.Top;
            stripe.Height = 4;
            stripe.BackColor = Theme.Navy;
            Controls.Add(stripe);

            // Urutan Controls: elemen terakhir ditambahkan berada paling atas saat Dock.
            Controls.SetChildIndex(body, 0);
            Controls.SetChildIndex(statusBar, 1);
            Controls.SetChildIndex(header, 2);
            Controls.SetChildIndex(stripe, 3);

            auto.Interval = 15000;
            auto.Tick += delegate { Refresh_(); };
            Shown += delegate { Refresh_(); auto.Start(); };
        }

        void BuildHeader(Panel header)
        {
            PictureBox logo = new PictureBox();
            logo.Image = Theme.Logo();
            logo.SizeMode = PictureBoxSizeMode.Zoom;
            logo.Size = new Size(190, 53);
            logo.Location = new Point(20, 18);
            header.Controls.Add(logo);

            Label t = new Label();
            t.Text = "SIMA Asset Agent";
            t.Font = new Font("Segoe UI Semibold", 15f);
            t.ForeColor = Theme.Navy;
            t.AutoSize = true;
            t.Location = new Point(230, 18);
            header.Controls.Add(t);

            Label s = new Label();
            s.Text = "Status agent monitoring aset di perangkat ini";
            s.ForeColor = Theme.Muted;
            s.AutoSize = true;
            s.Location = new Point(232, 56);
            header.Controls.Add(s);

            Panel line = new Panel();
            line.Dock = DockStyle.Bottom;
            line.Height = 1;
            line.BackColor = Theme.Line;
            header.Controls.Add(line);
        }

        // Tata letak memakai Dock (bukan koordinat tetap) supaya mengikuti ukuran jendela dan skala DPI.
        // Urutan Controls.Add: yang ditambahkan PERTAMA = paling bawah/terakhir diproses (Fill), yang TERAKHIR = paling atas.
        static Panel Spacer(int h)
        {
            Panel p = new Panel();
            p.Dock = DockStyle.Top;
            p.Height = h;
            p.BackColor = Color.Transparent;
            return p;
        }

        void BuildBody(Panel body)
        {
            // 1) Kotak riwayat (mengisi sisa ruang)
            logBox.Multiline = true;
            logBox.ReadOnly = true;
            logBox.ScrollBars = ScrollBars.Vertical;
            logBox.Font = new Font("Consolas", 9f);
            logBox.BackColor = Color.White;
            logBox.ForeColor = Theme.Ink;
            logBox.BorderStyle = BorderStyle.FixedSingle;
            logBox.Dock = DockStyle.Fill;
            body.Controls.Add(logBox);

            // 2) Judul riwayat
            Label lh = new Label();
            lh.Text = "Riwayat laporan terakhir";
            lh.Font = new Font("Segoe UI Semibold", 10f);
            lh.ForeColor = Theme.Navy;
            lh.Dock = DockStyle.Top;
            lh.Height = 28;
            lh.TextAlign = ContentAlignment.BottomLeft;
            body.Controls.Add(lh);

            // 3) Tombol
            FlowLayoutPanel btns = new FlowLayoutPanel();
            btns.Dock = DockStyle.Top;
            btns.Height = 44;
            btns.Padding = new Padding(0, 4, 0, 0);
            btnSend = MakeButton("Kirim laporan sekarang", true);
            btnRefresh = MakeButton("Segarkan", false);
            btnTest = MakeButton("Tes koneksi", false);
            btnLog = MakeButton("Buka file log", false);
            btnSend.Click += delegate { SendNow(); };
            btnRefresh.Click += delegate { Refresh_(); };
            btnTest.Click += delegate { TestConnection(); };
            btnLog.Click += delegate { OpenLog(); };
            btns.Controls.AddRange(new Control[] { btnSend, btnRefresh, btnTest, btnLog });
            body.Controls.Add(btns);
            body.Controls.Add(Spacer(6));

            // 4) Kartu informasi
            string[,] cards = new string[,]
            {
                { "server", "Server SIMA" }, { "host", "Nama perangkat" },
                { "ip", "Alamat IP" }, { "last", "Laporan terakhir" },
                { "next", "Laporan berikutnya" }, { "task", "Status penjadwal" },
                { "result", "Hasil proses terakhir" }, { "interval", "Interval laporan" },
                { "version", "Versi agent" }, { "update", "Pembaruan otomatis" },
                { "token", "Token agent" }, { "folder", "Lokasi instalasi" }
            };
            TableLayoutPanel grid = new TableLayoutPanel();
            grid.ColumnCount = 2;
            grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
            grid.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 50));
            int rows = cards.GetLength(0) / 2;
            grid.RowCount = rows;
            for (int r = 0; r < rows; r++) grid.RowStyles.Add(new RowStyle(SizeType.Absolute, 72));
            grid.Dock = DockStyle.Top;
            grid.Height = 72 * rows;
            grid.Margin = new Padding(0);
            for (int i = 0; i < cards.GetLength(0); i++)
            {
                Panel card = MakeCard(cards[i, 0], cards[i, 1]);
                grid.Controls.Add(card, i % 2, i / 2);
            }
            body.Controls.Add(grid);
            body.Controls.Add(Spacer(8));

            // 5) Banner status (paling atas)
            banner.Dock = DockStyle.Top;
            banner.Height = 96;
            banner.Padding = new Padding(20, 10, 20, 6);
            bannerSub.AutoSize = false;
            bannerSub.Dock = DockStyle.Fill;
            bannerTitle.Font = new Font("Segoe UI Semibold", 17f);
            bannerTitle.AutoSize = false;
            bannerTitle.Dock = DockStyle.Top;
            bannerTitle.Height = 42;
            banner.Controls.Add(bannerSub);
            banner.Controls.Add(bannerTitle);
            body.Controls.Add(banner);
            SetBanner("Memeriksa status...", "Mohon tunggu sebentar", "grey");
        }

        Panel MakeCard(string key, string caption)
        {
            Panel card = new Panel();
            card.Dock = DockStyle.Fill;
            card.BackColor = Color.White;
            card.Margin = new Padding(0, 0, 10, 8);
            card.Padding = new Padding(14, 8, 10, 6);
            card.Paint += delegate(object o, PaintEventArgs e)
            {
                using (Pen p = new Pen(Theme.Line)) e.Graphics.DrawRectangle(p, 0, 0, card.Width - 1, card.Height - 1);
                using (SolidBrush b = new SolidBrush(Theme.Sky)) e.Graphics.FillRectangle(b, 0, 0, 3, card.Height);
            };
            Label cap = new Label();
            cap.Text = caption.ToUpperInvariant();
            cap.Font = new Font("Segoe UI", 7.5f, FontStyle.Bold);
            cap.ForeColor = Theme.Muted;
            cap.Dock = DockStyle.Top;
            cap.Height = 20;
            Label val = new Label();
            val.Text = "-";
            val.Font = new Font("Segoe UI Semibold", 10.5f);
            val.ForeColor = Theme.Ink;
            val.Dock = DockStyle.Fill;
            val.AutoEllipsis = true;
            val.TextAlign = ContentAlignment.MiddleLeft;
            card.Controls.Add(val);
            card.Controls.Add(cap);
            values[key] = val;
            return card;
        }

        Button MakeButton(string text, bool primary)
        {
            Button b = new Button();
            b.Text = text;
            b.AutoSize = true;
            b.MinimumSize = new Size(0, 36);
            b.Padding = new Padding(10, 0, 10, 0);
            b.Margin = new Padding(0, 0, 8, 0);
            b.FlatStyle = FlatStyle.Flat;
            b.Cursor = Cursors.Hand;
            b.Font = new Font("Segoe UI Semibold", 9.5f);
            if (primary)
            {
                b.BackColor = Theme.Navy;
                b.ForeColor = Color.White;
                b.FlatAppearance.BorderSize = 0;
            }
            else
            {
                b.BackColor = Color.White;
                b.ForeColor = Theme.Navy;
                b.FlatAppearance.BorderColor = Theme.Line;
            }
            return b;
        }

        void SetBanner(string title, string sub, string kind)
        {
            Color back, fore;
            switch (kind)
            {
                case "ok": back = ColorTranslator.FromHtml("#E6F6EE"); fore = ColorTranslator.FromHtml("#11743D"); break;
                case "warn": back = ColorTranslator.FromHtml("#FFF4E0"); fore = ColorTranslator.FromHtml("#9A5B00"); break;
                case "err": back = ColorTranslator.FromHtml("#FDE8E8"); fore = ColorTranslator.FromHtml("#B42318"); break;
                default: back = ColorTranslator.FromHtml("#EAEEF4"); fore = ColorTranslator.FromHtml("#475467"); break;
            }
            banner.BackColor = back;
            bannerTitle.BackColor = back;
            bannerSub.BackColor = back;
            bannerTitle.ForeColor = fore;
            bannerSub.ForeColor = fore;
            bannerTitle.Text = title;
            bannerSub.Text = sub;
        }

        void SetBusy(bool b)
        {
            busy = b;
            btnSend.Enabled = !b;
            btnRefresh.Enabled = !b;
            btnTest.Enabled = !b;
        }

        // ---------- Pengumpulan data (di thread terpisah, supaya UI tidak membeku) ----------

        void Refresh_()
        {
            if (busy) return;
            SetBusy(true);
            ThreadPool.QueueUserWorkItem(delegate
            {
                AgentInfo info;
                try { info = Collect(appDir); }
                catch (Exception ex)
                {
                    info = new AgentInfo();
                    info.AppDir = appDir;
                    info.LastLine = "Gagal membaca status: " + ex.Message;
                }
                try { BeginInvoke(new MethodInvoker(delegate { Apply(info); SetBusy(false); })); }
                catch (InvalidOperationException) { }
            });
        }

        static AgentInfo Collect(string dir)
        {
            AgentInfo i = new AgentInfo();
            i.AppDir = dir;

            string cfg = Path.Combine(dir, "config.json");
            if (File.Exists(cfg))
            {
                i.ConfigFound = true;
                string txt = File.ReadAllText(cfg, Encoding.UTF8);
                Match m = Regex.Match(txt, "\"simaUrl\"\\s*:\\s*\"([^\"]*)\"");
                if (m.Success) i.SimaUrl = m.Groups[1].Value.Replace("\\/", "/");
                Match t = Regex.Match(txt, "\"token\"\\s*:\\s*\"([^\"]+)\"");
                i.HasToken = t.Success && t.Groups[1].Value.Length >= 20;
            }

            string log = Path.Combine(dir, "agent.log");
            if (File.Exists(log))
            {
                i.LogFound = true;
                List<string> lines = new List<string>();
                using (FileStream fs = new FileStream(log, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
                using (StreamReader sr = new StreamReader(fs, Encoding.UTF8))
                {
                    string l;
                    while ((l = sr.ReadLine()) != null) if (l.Trim().Length > 0) lines.Add(l);
                }
                int from = Math.Max(0, lines.Count - 40);
                for (int n = from; n < lines.Count; n++) i.LogTail.Add(lines[n]);
                if (lines.Count > 0)
                {
                    i.LastLine = lines[lines.Count - 1];
                    i.LastIsError = i.LastLine.IndexOf("GAGAL", StringComparison.OrdinalIgnoreCase) >= 0;
                    if (i.LastIsError)
                    {
                        int p = i.LastLine.IndexOf("GAGAL", StringComparison.OrdinalIgnoreCase);
                        i.LastError = i.LastLine.Substring(p);
                    }
                }
                for (int n = lines.Count - 1; n >= 0; n--)
                {
                    if (i.LastUpdate.Length == 0)
                    {
                        Match up = Regex.Match(lines[n], @"^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+UPDATE\s+(.*)$");
                        if (up.Success)
                        {
                            string when = up.Groups[1].Value.Substring(5, 11);
                            string msg = up.Groups[2].Value;
                            Match okm = Regex.Match(msg, @"^OK (v\d+ -> v\d+)");
                            if (okm.Success) i.LastUpdate = "Berhasil " + okm.Groups[1].Value + " (" + when + ")";
                            else if (msg.StartsWith("GAGAL", StringComparison.OrdinalIgnoreCase)) i.LastUpdate = "Gagal (" + when + ") - lihat log";
                            else i.LastUpdate = "Memeriksa versi baru (" + when + ")";
                        }
                    }
                    Match ok = Regex.Match(lines[n], @"^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+OK\b.*?hostname=(\S+)\s+ip=(\S*)");
                    if (ok.Success && !i.LastOk.HasValue)
                    {
                        Match vv = Regex.Match(lines[n], @"\sOK v(\d+)\s");
                        if (vv.Success) i.AgentVersion = int.Parse(vv.Groups[1].Value);
                        DateTime d;
                        if (DateTime.TryParseExact(ok.Groups[1].Value, "yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture, DateTimeStyles.None, out d))
                        {
                            i.LastOk = d;
                            i.LastOkHost = ok.Groups[2].Value;
                            i.LastOkIp = ok.Groups[3].Value;
                        }
                    }
                    // Berhenti setelah laporan sukses terakhir DAN catatan update terakhir sama-sama ditemukan.
                    if (i.LastOk.HasValue && i.LastUpdate.Length > 0) break;
                }
            }

            string script =
                "$t=Get-ScheduledTask -TaskName '" + TaskName + "' -ErrorAction SilentlyContinue\r\n" +
                "if(-not $t){ 'NONE'; exit }\r\n" +
                "$i=Get-ScheduledTaskInfo -TaskName '" + TaskName + "'\r\n" +
                "$iv=''; try{ $iv=[string]$t.Triggers[0].Repetition.Interval }catch{}\r\n" +
                "function f($d){ if($d -and $d.Year -gt 2000){ $d.ToString('yyyy-MM-dd HH:mm:ss') } else { '' } }\r\n" +
                "('{0}|{1}|{2}|{3}|{4}' -f $t.State,(f $i.LastRunTime),(f $i.NextRunTime),$i.LastTaskResult,$iv)";
            string outp = RunPowerShell(script, 20000).Trim();
            if (outp.Length > 0 && outp != "NONE" && outp.IndexOf('|') > 0)
            {
                string[] p = outp.Split('|');
                if (p.Length >= 5)
                {
                    i.TaskExists = true;
                    i.TaskState = p[0];
                    i.TaskLastRun = p[1];
                    i.TaskNextRun = p[2];
                    i.TaskResult = p[3];
                    Match iv = Regex.Match(p[4], @"PT(?:(\d+)H)?(?:(\d+)M)?");
                    if (iv.Success)
                    {
                        int h = iv.Groups[1].Success ? int.Parse(iv.Groups[1].Value) : 0;
                        int mi = iv.Groups[2].Success ? int.Parse(iv.Groups[2].Value) : 0;
                        if (h * 60 + mi > 0) i.IntervalMinutes = h * 60 + mi;
                    }
                }
            }
            return i;
        }

        static string RunPowerShell(string script, int timeoutMs)
        {
            ProcessStartInfo psi = new ProcessStartInfo("powershell.exe",
                "-NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand " +
                Convert.ToBase64String(Encoding.Unicode.GetBytes(script)));
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            using (Process p = Process.Start(psi))
            {
                string o = p.StandardOutput.ReadToEnd();
                if (!p.WaitForExit(timeoutMs))
                {
                    try { p.Kill(); } catch { }
                    return "";
                }
                return o;
            }
        }

        // ---------- Tampilan ----------

        static string Ago(DateTime d)
        {
            TimeSpan t = DateTime.Now - d;
            if (t.TotalSeconds < 0) t = TimeSpan.Zero;
            if (t.TotalSeconds < 60) return "baru saja";
            if (t.TotalMinutes < 60) return ((int)t.TotalMinutes) + " menit lalu";
            if (t.TotalHours < 48) return ((int)t.TotalHours) + " jam lalu";
            return ((int)t.TotalDays) + " hari lalu";
        }

        static string ResultText(string code)
        {
            long v;
            if (!long.TryParse(code, out v)) return string.IsNullOrEmpty(code) ? "-" : code;
            if (v == 0) return "Berhasil (0)";
            if (v == 267011) return "Belum pernah berjalan";
            if (v == 267009) return "Sedang berjalan";
            return "Kode 0x" + v.ToString("X");
        }

        void Apply(AgentInfo i)
        {
            current = i;
            values["server"].Text = i.SimaUrl.Length > 0 ? i.SimaUrl : (i.ConfigFound ? "(kosong)" : "config.json tidak ditemukan");
            values["host"].Text = i.LastOkHost.Length > 0 ? i.LastOkHost : Environment.MachineName;
            values["ip"].Text = i.LastOkIp.Length > 0 ? i.LastOkIp : "-";
            values["last"].Text = i.LastOk.HasValue ? i.LastOk.Value.ToString("dd MMM yyyy HH:mm:ss", CultureInfo.GetCultureInfo("id-ID")) + "  (" + Ago(i.LastOk.Value) + ")" : "Belum pernah";
            values["next"].Text = !string.IsNullOrEmpty(i.TaskNextRun) ? i.TaskNextRun : "-";
            values["task"].Text = i.TaskExists ? i.TaskState : "Belum terpasang";
            values["result"].Text = i.TaskExists ? ResultText(i.TaskResult) : "-";
            values["interval"].Text = i.TaskExists ? ("Setiap " + i.IntervalMinutes + " menit") : "-";
            values["version"].Text = i.AgentVersion > 0 ? ("v" + i.AgentVersion) : "-";
            values["update"].Text = i.LastUpdate.Length > 0 ? i.LastUpdate : "Belum ada pembaruan";
            values["token"].Text = i.HasToken ? "Sudah diisi (disembunyikan)" : "Belum diisi";
            values["folder"].Text = i.AppDir.TrimEnd('\\');

            logBox.Text = string.Join(Environment.NewLine, i.LogTail.ToArray());
            logBox.SelectionStart = logBox.TextLength;
            logBox.ScrollToCaret();

            double staleMin = (2 * i.IntervalMinutes) + 2;
            if (!i.TaskExists && !i.LogFound)
            {
                SetBanner("Agent belum terpasang", "Penjadwal \"" + TaskName + "\" tidak ditemukan. Jalankan ulang installer sebagai Administrator.", "grey");
            }
            else if (i.LastIsError)
            {
                SetBanner("Gagal mengirim laporan", "Penyebab: " + Regex.Replace(i.LastError, @"^GAGAL[^:]*:\s*", ""), "err");
            }
            else if (i.LastOk.HasValue && (DateTime.Now - i.LastOk.Value).TotalMinutes <= staleMin)
            {
                SetBanner("Online", "Agent berjalan normal. Laporan terakhir terkirim " + Ago(i.LastOk.Value) + ".", "ok");
            }
            else if (i.LastOk.HasValue)
            {
                SetBanner("Tidak melapor", "Laporan terakhir " + Ago(i.LastOk.Value) + ", melewati batas wajar (" + (int)staleMin + " menit). Periksa koneksi internet dan status penjadwal.", "warn");
            }
            else
            {
                SetBanner("Menunggu laporan pertama", "Belum ada laporan yang berhasil. Klik \"Kirim laporan sekarang\".", "warn");
            }
            statusBar.Text = "Diperbarui " + DateTime.Now.ToString("HH:mm:ss") + "   |   Otomatis diperbarui tiap 15 detik   |   PT Adhi Kartiko Pratama Tbk";
        }

        // ---------- Aksi ----------

        void SendNow()
        {
            SetBusy(true);
            statusBar.Text = "Mengirim laporan ke server...";
            ThreadPool.QueueUserWorkItem(delegate
            {
                RunPowerShell("Start-ScheduledTask -TaskName '" + TaskName + "'", 20000);
                Thread.Sleep(7000);
                try { BeginInvoke(new MethodInvoker(delegate { SetBusy(false); Refresh_(); })); }
                catch (InvalidOperationException) { }
            });
        }

        void TestConnection()
        {
            if (current.SimaUrl.Length == 0)
            {
                MessageBox.Show(this, "Alamat server belum diisi di config.json.", "Tes koneksi", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                return;
            }
            SetBusy(true);
            statusBar.Text = "Menguji koneksi ke " + current.SimaUrl + " ...";
            string url = current.SimaUrl.TrimEnd('/') + "/health";
            ThreadPool.QueueUserWorkItem(delegate
            {
                string msg;
                MessageBoxIcon icon = MessageBoxIcon.Information;
                Stopwatch sw = Stopwatch.StartNew();
                try
                {
                    ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072 | SecurityProtocolType.Tls;
                    HttpWebRequest rq = (HttpWebRequest)WebRequest.Create(url);
                    rq.Timeout = 10000;
                    rq.UserAgent = "SIMA-Agent-Status";
                    using (HttpWebResponse rs = (HttpWebResponse)rq.GetResponse())
                    {
                        msg = "Server dapat dijangkau.\r\n\r\n" + url + "\r\nHTTP " + (int)rs.StatusCode + "  -  " + sw.ElapsedMilliseconds + " ms";
                    }
                }
                catch (WebException ex)
                {
                    HttpWebResponse r = ex.Response as HttpWebResponse;
                    if (r != null)
                    {
                        msg = "Server menjawab tetapi dengan HTTP " + (int)r.StatusCode + ".\r\n\r\n" + url;
                        icon = MessageBoxIcon.Warning;
                    }
                    else
                    {
                        msg = "Tidak dapat menjangkau server.\r\n\r\n" + url + "\r\n" + ex.Message;
                        icon = MessageBoxIcon.Error;
                    }
                }
                catch (Exception ex)
                {
                    msg = "Tes koneksi gagal: " + ex.Message;
                    icon = MessageBoxIcon.Error;
                }
                try
                {
                    BeginInvoke(new MethodInvoker(delegate
                    {
                        SetBusy(false);
                        MessageBox.Show(this, msg, "Tes koneksi", MessageBoxButtons.OK, icon);
                        statusBar.Text = "Tes koneksi selesai " + DateTime.Now.ToString("HH:mm:ss");
                    }));
                }
                catch (InvalidOperationException) { }
            });
        }

        void OpenLog()
        {
            string log = Path.Combine(appDir, "agent.log");
            if (!File.Exists(log))
            {
                MessageBox.Show(this, "File log belum ada karena agent belum pernah berjalan.", "Buka log", MessageBoxButtons.OK, MessageBoxIcon.Information);
                return;
            }
            Process.Start("notepad.exe", "\"" + log + "\"");
        }
    }
}
