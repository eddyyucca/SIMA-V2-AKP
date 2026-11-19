// SIMA Agent Helper - program kecil di SESI PENGGUNA yang menampilkan pengumuman dari IT dan melayani chat yang
// dimulai IT. Dikompilasi dengan csc bawaan Windows (.NET Framework 4, C# 5): lihat build.ps1.
//
// Batas yang dijaga (sengaja):
//  - berjalan sebagai pengguna biasa (tanpa UAC), ikon tray selalu terlihat, dan semua aktivitas tercatat di helper.log;
//  - HANYA menampilkan pengumuman dan chat; tidak ada kemampuan lain (tidak membaca file, layar, atau papan klip);
//  - hanya IT yang memulai dan menutup chat: tombol tutup (X) di jendela chat hanya menyembunyikannya ke tray,
//    percakapan berakhir hanya saat server menyatakan sesi ditutup;
//  - memakai token KHUSUS (C:\ProgramData\SIMA\helper.json) yang cakupannya hanya pengumuman dan chat aset ini.
using System;
using System.Collections;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.Media;
using System.Net;
using System.Reflection;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;
using System.Windows.Forms;

namespace Akp.SimaAgentHelper
{
    static class Theme
    {
        public static readonly Color Navy = ColorTranslator.FromHtml("#27386B");
        public static readonly Color Sky = ColorTranslator.FromHtml("#4FA6DC");
        public static readonly Color Ink = ColorTranslator.FromHtml("#1D2939");
        public static readonly Color Muted = ColorTranslator.FromHtml("#667085");
        public static readonly Color Line = ColorTranslator.FromHtml("#DDE3EC");
        public static readonly Color Bg = ColorTranslator.FromHtml("#F3F6FA");
        public static readonly Color Info = ColorTranslator.FromHtml("#3C8DBC");
        public static readonly Color Important = ColorTranslator.FromHtml("#F39C12");
        public static readonly Color Urgent = ColorTranslator.FromHtml("#DD4B39");

        public static Color Severity(string s)
        {
            if (s == "urgent") return Urgent;
            if (s == "important") return Important;
            return Info;
        }

        public static string SeverityLabel(string s)
        {
            if (s == "urgent") return "MENDESAK";
            if (s == "important") return "PENTING";
            return "INFORMASI";
        }

        static Image logo;
        public static Image Logo()
        {
            if (logo != null) return logo;
            using (Stream st = Assembly.GetExecutingAssembly().GetManifestResourceStream("akp-logo.png"))
            {
                if (st != null) logo = Image.FromStream(st);
            }
            return logo;
        }
    }

    /// <summary>Penskalaan DPI manual: ukuran piksel dirancang untuk 96 DPI dan dikalikan DPI layar sebenarnya.</summary>
    static class Ui
    {
        static readonly float scale = Measure();

        static float Measure()
        {
            try
            {
                using (Graphics g = Graphics.FromHwnd(IntPtr.Zero)) return Math.Max(1f, g.DpiX / 96f);
            }
            catch { return 1f; }
        }

        public static int S(int px) { return (int)Math.Round(px * scale); }
    }
    static class Log
    {
        static readonly object gate = new object();

        public static string PathName()
        {
            string dir = System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SIMA");
            Directory.CreateDirectory(dir);
            return System.IO.Path.Combine(dir, "helper.log");
        }

        public static void Write(string message)
        {
            try
            {
                lock (gate)
                {
                    string p = PathName();
                    if (File.Exists(p) && new FileInfo(p).Length > 200 * 1024)
                    {
                        string[] lines = File.ReadAllLines(p);
                        int from = Math.Max(0, lines.Length - 200);
                        string[] tail = new string[lines.Length - from];
                        Array.Copy(lines, from, tail, 0, tail.Length);
                        File.WriteAllLines(p, tail);
                    }
                    File.AppendAllText(p, DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + message + Environment.NewLine);
                }
            }
            catch { }
        }
    }

    // ---------- Klien API ----------

    class PollResult
    {
        public int PollSeconds = 20;
        public List<Announcement> Announcements = new List<Announcement>();
        public int SessionId;                 // 0 = tidak ada sesi
        public string SessionStatus = "";     // open | closed
        public string CloseReason = "";
        public string StartedBy = "";
        public List<ChatMsg> Messages = new List<ChatMsg>();
    }

    class Announcement
    {
        public int Id;
        public string Title = "";
        public string Body = "";
        public string Severity = "info";
        public bool RequireAck;
    }

    class ChatMsg
    {
        public int Id;
        public string Sender = "";   // it | user
        public string Name = "";
        public string Body = "";
        public DateTime At = DateTime.Now;
    }

    class ApiClient
    {
        readonly JavaScriptSerializer json = new JavaScriptSerializer { MaxJsonLength = 4 * 1024 * 1024 };
        string baseUrl = "";
        string token = "";

        public static string ConfigPath()
        {
            return System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SIMA", "helper.json");
        }

        /// <summary>Membaca token terbaru dari berkas yang ditulis agent (berubah saat dirotasi).</summary>
        public bool LoadConfig(out string problem)
        {
            problem = "";
            try
            {
                string path = ConfigPath();
                if (!File.Exists(path)) { problem = "helper.json belum ada (agent belum membuatnya)"; return false; }
                Dictionary<string, object> d = json.DeserializeObject(File.ReadAllText(path, Encoding.UTF8)) as Dictionary<string, object>;
                if (d == null) { problem = "helper.json tidak valid"; return false; }
                baseUrl = Str(d, "simaUrl").TrimEnd('/');
                token = Str(d, "token");
                if (baseUrl.Length == 0 || token.Length == 0) { problem = "helper.json tidak lengkap"; return false; }
                return true;
            }
            catch (Exception ex) { problem = ex.Message; return false; }
        }

        public void Configure(string url, string accessToken) { baseUrl = url.TrimEnd('/'); token = accessToken; }

        public PollResult Poll(int chatSession, int after)
        {
            string body = Request("GET", "/api/helper/v1/poll?chat_session=" + chatSession + "&after=" + after, null);
            return ParsePoll(body);
        }

        public void MarkShown(int id) { Request("POST", "/api/helper/v1/announcements/" + id + "/shown", "{}"); }

        public void Ack(int id) { Request("POST", "/api/helper/v1/announcements/" + id + "/ack", "{}"); }

        /// <summary>Mengirim balasan pengguna; mengembalikan pesan yang tersimpan, atau null bila sesi sudah ditutup.</summary>
        public ChatMsg SendChat(int sessionId, string text)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["session_id"] = sessionId;
            payload["body"] = text;
            string body;
            try { body = Request("POST", "/api/helper/v1/chat/messages", json.Serialize(payload)); }
            catch (WebException ex)
            {
                HttpWebResponse r = ex.Response as HttpWebResponse;
                if (r != null && (int)r.StatusCode == 409) return null;
                throw;
            }
            Dictionary<string, object> d = json.DeserializeObject(body) as Dictionary<string, object>;
            if (d == null || Str(d, "status") != "ok") return null;
            return ParseMessage(d.ContainsKey("message") ? d["message"] as Dictionary<string, object> : null);
        }

        string Request(string method, string path, string payload)
        {
            ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072 | SecurityProtocolType.Tls;
            HttpWebRequest rq = (HttpWebRequest)WebRequest.Create(baseUrl + path);
            rq.Method = method;
            rq.Timeout = 15000;
            rq.UserAgent = "SIMA-Agent-Helper";
            rq.Headers["Authorization"] = "Bearer " + token;
            rq.Accept = "application/json";
            if (payload != null)
            {
                byte[] data = Encoding.UTF8.GetBytes(payload);
                rq.ContentType = "application/json; charset=utf-8";
                rq.ContentLength = data.Length;
                using (Stream s = rq.GetRequestStream()) s.Write(data, 0, data.Length);
            }
            using (HttpWebResponse rs = (HttpWebResponse)rq.GetResponse())
            using (StreamReader sr = new StreamReader(rs.GetResponseStream(), Encoding.UTF8))
            {
                return sr.ReadToEnd();
            }
        }

        // ---- parsing ----

        public PollResult ParsePoll(string body)
        {
            PollResult r = new PollResult();
            Dictionary<string, object> d = json.DeserializeObject(body) as Dictionary<string, object>;
            if (d == null) return r;
            r.PollSeconds = Math.Max(2, Math.Min(120, Int(d, "poll_seconds", 20)));

            foreach (object o in List(d, "announcements"))
            {
                Dictionary<string, object> a = o as Dictionary<string, object>;
                if (a == null) continue;
                Announcement an = new Announcement();
                an.Id = Int(a, "id", 0);
                an.Title = Str(a, "title");
                an.Body = Str(a, "body");
                an.Severity = Str(a, "severity");
                an.RequireAck = Bool(a, "require_ack");
                if (an.Id > 0) r.Announcements.Add(an);
            }

            Dictionary<string, object> chat = d.ContainsKey("chat") ? d["chat"] as Dictionary<string, object> : null;
            if (chat != null)
            {
                Dictionary<string, object> s = chat.ContainsKey("session") ? chat["session"] as Dictionary<string, object> : null;
                if (s != null)
                {
                    r.SessionId = Int(s, "id", 0);
                    r.SessionStatus = Str(s, "status");
                    r.CloseReason = Str(s, "close_reason");
                    r.StartedBy = Str(s, "started_by");
                }
                foreach (object o in List(chat, "messages"))
                {
                    ChatMsg m = ParseMessage(o as Dictionary<string, object>);
                    if (m != null) r.Messages.Add(m);
                }
            }
            return r;
        }

        ChatMsg ParseMessage(Dictionary<string, object> m)
        {
            if (m == null) return null;
            ChatMsg c = new ChatMsg();
            c.Id = Int(m, "id", 0);
            c.Sender = Str(m, "sender");
            c.Name = Str(m, "name");
            c.Body = Str(m, "body");
            DateTime t;
            if (DateTime.TryParse(Str(m, "created_at"), null, System.Globalization.DateTimeStyles.AdjustToUniversal | System.Globalization.DateTimeStyles.AssumeUniversal, out t)) c.At = t.ToLocalTime();
            return c.Id > 0 ? c : null;
        }

        static string Str(Dictionary<string, object> d, string k) { object v; return d.TryGetValue(k, out v) && v != null ? Convert.ToString(v) : ""; }
        static int Int(Dictionary<string, object> d, string k, int def) { object v; int n; return d.TryGetValue(k, out v) && v != null && int.TryParse(Convert.ToString(v), out n) ? n : def; }
        static bool Bool(Dictionary<string, object> d, string k) { object v; return d.TryGetValue(k, out v) && v is bool && (bool)v; }
        static IEnumerable List(Dictionary<string, object> d, string k) { object v; return d.TryGetValue(k, out v) && v is IEnumerable && !(v is string) ? (IEnumerable)v : new object[0]; }
    }

    // ---------- Kartu pengumuman ----------

    class AnnouncementForm : Form
    {
        public event EventHandler Dismissed;
        readonly Announcement ann;
        bool allowClose;

        public AnnouncementForm(Announcement a)
        {
            ann = a;
            AutoScaleMode = AutoScaleMode.None;           // ukuran dihitung sendiri lewat Ui.S (penskalaan otomatis tidak andal)
            FormBorderStyle = FormBorderStyle.None;
            ShowInTaskbar = false;
            TopMost = true;
            StartPosition = FormStartPosition.Manual;
            BackColor = Color.White;
            Font = new Font("Segoe UI", 9.5f);
            KeyPreview = true;

            int width = Ui.S(430), pad = Ui.S(18);
            int textW = width - 2 * pad;
            Color sev = Theme.Severity(a.Severity);

            Panel stripe = new Panel { Dock = DockStyle.Top, Height = Ui.S(7), BackColor = sev };

            Panel head = new Panel { Dock = DockStyle.Top, Height = Ui.S(46) };
            PictureBox logo = new PictureBox { Image = Theme.Logo(), SizeMode = PictureBoxSizeMode.Zoom, Size = new Size(Ui.S(84), Ui.S(24)), Location = new Point(pad, Ui.S(14)) };
            Label sevLabel = new Label
            {
                Text = Theme.SeverityLabel(a.Severity), ForeColor = sev, Font = new Font("Segoe UI", 8f, FontStyle.Bold),
                AutoSize = false, TextAlign = ContentAlignment.MiddleRight, Dock = DockStyle.Right, Width = Ui.S(160), Padding = new Padding(0, 0, pad, 0)
            };
            head.Controls.Add(sevLabel);
            head.Controls.Add(logo);

            Font titleFont = new Font("Segoe UI Semibold", 13.5f);
            Size ts = TextRenderer.MeasureText(a.Title, titleFont, new Size(textW, 0), TextFormatFlags.WordBreak);
            Label title = new Label
            {
                Text = a.Title, Font = titleFont, ForeColor = Theme.Ink, AutoSize = false, Dock = DockStyle.Top,
                Padding = new Padding(pad, 0, pad, 0), Height = Math.Min(ts.Height + Ui.S(12), Ui.S(96))
            };

            Size bs = TextRenderer.MeasureText(a.Body, Font, new Size(textW, 0), TextFormatFlags.WordBreak);
            int bodyH = Math.Max(Ui.S(44), Math.Min(bs.Height + Ui.S(24), Ui.S(290)));
            Panel body = new Panel { Dock = DockStyle.Fill, AutoScroll = true };
            Label text = new Label
            {
                Text = a.Body, ForeColor = Color.FromArgb(71, 84, 103), AutoSize = true,
                MaximumSize = new Size(textW - SystemInformation.VerticalScrollBarWidth, 0), Location = new Point(pad, Ui.S(6))
            };
            body.Controls.Add(text);

            Font btnFont = new Font("Segoe UI Semibold", 9.5f);
            string btnText = a.RequireAck ? "Saya sudah membaca" : "Tutup";
            Panel foot = new Panel { Dock = DockStyle.Bottom, Height = Ui.S(62), Padding = new Padding(pad, Ui.S(10), pad, Ui.S(14)) };
            Button btn = new Button
            {
                FlatStyle = FlatStyle.Flat, Cursor = Cursors.Hand, Dock = DockStyle.Right, Font = btnFont, Text = btnText,
                Width = TextRenderer.MeasureText(btnText, btnFont).Width + Ui.S(48)
            };
            btn.FlatAppearance.BorderSize = 0;
            btn.BackColor = a.RequireAck ? Theme.Navy : Color.FromArgb(238, 241, 246);
            btn.ForeColor = a.RequireAck ? Color.White : Theme.Navy;
            btn.Click += delegate { allowClose = true; Close(); };
            foot.Controls.Add(btn);

            ClientSize = new Size(width, stripe.Height + head.Height + title.Height + bodyH + foot.Height);

            Controls.Add(body);
            Controls.Add(foot);
            Controls.Add(title);
            Controls.Add(head);
            Controls.Add(stripe);

            Rectangle wa = Screen.PrimaryScreen.WorkingArea;
            Location = new Point(wa.Right - Width - Ui.S(20), wa.Bottom - Height - Ui.S(20));

            // Pengumuman WAJIB dikonfirmasi tidak bisa ditutup dengan Esc/Alt+F4 sebelum tombol ditekan.
            KeyDown += delegate (object s, KeyEventArgs e) { if (e.KeyCode == Keys.Escape && !ann.RequireAck) { allowClose = true; Close(); } };
            FormClosing += delegate (object s, FormClosingEventArgs e)
            {
                if (ann.RequireAck && !allowClose && e.CloseReason == CloseReason.UserClosing) e.Cancel = true;
            };
            FormClosed += delegate { if (Dismissed != null) Dismissed(this, EventArgs.Empty); };
        }

        public bool Acknowledged { get { return ann.RequireAck && allowClose; } }
        public Announcement Data { get { return ann; } }

        protected override void OnPaint(PaintEventArgs e)
        {
            base.OnPaint(e);
            using (Pen p = new Pen(Theme.Line)) e.Graphics.DrawRectangle(p, 0, 0, Width - 1, Height - 1);
        }

        protected override bool ShowWithoutActivation { get { return true; } }   // tidak merebut fokus ketikan pengguna
    }

    // ---------- Jendela chat ----------

    class ChatForm : Form
    {
        public event Action<string> SendRequested;
        readonly FlowLayoutPanel thread = new FlowLayoutPanel();
        readonly TextBox input = new TextBox();
        readonly Button send = new Button();
        readonly Label status = new Label();
        bool sessionActive = true;

        public ChatForm(string startedBy)
        {
            AutoScaleMode = AutoScaleMode.None;
            Text = "Chat dengan IT AKP";
            ClientSize = new Size(Ui.S(440), Ui.S(580));
            MinimumSize = new Size(Ui.S(400), Ui.S(500));
            StartPosition = FormStartPosition.Manual;
            BackColor = Theme.Bg;
            Font = new Font("Segoe UI", 9.5f);
            MaximizeBox = false;
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }
            Rectangle wa = Screen.PrimaryScreen.WorkingArea;
            Location = new Point(wa.Right - Width - Ui.S(24), wa.Bottom - Height - Ui.S(24));

            Panel stripe = new Panel { Dock = DockStyle.Top, Height = Ui.S(4), BackColor = Theme.Navy };

            Panel head = new Panel { Dock = DockStyle.Top, Height = Ui.S(72), BackColor = Color.White };
            PictureBox logo = new PictureBox { Image = Theme.Logo(), SizeMode = PictureBoxSizeMode.Zoom, Size = new Size(Ui.S(96), Ui.S(27)), Location = new Point(Ui.S(14), Ui.S(10)) };
            Label who = new Label
            {
                Text = string.IsNullOrEmpty(startedBy) ? "Tim IT AKP" : startedBy + " (IT AKP)",
                Font = new Font("Segoe UI Semibold", 10.5f), ForeColor = Theme.Navy, AutoSize = true, Location = new Point(Ui.S(14), Ui.S(42))
            };
            head.Controls.Add(logo);
            head.Controls.Add(who);

            status.Dock = DockStyle.Top;
            status.AutoSize = false;
            status.Height = Ui.S(54);
            status.Padding = new Padding(Ui.S(14), Ui.S(6), Ui.S(14), 0);
            status.ForeColor = Theme.Muted;
            status.Font = new Font("Segoe UI", 8.5f);
            status.Text = "Percakapan ini dimulai oleh IT dan hanya IT yang dapat mengakhirinya. Anda dapat meminimalkan jendela ini.";

            Panel foot = new Panel { Dock = DockStyle.Bottom, Height = Ui.S(64), Padding = new Padding(Ui.S(12), Ui.S(12), Ui.S(12), Ui.S(12)), BackColor = Color.White };
            send.Text = "Kirim";
            send.Dock = DockStyle.Right;
            send.Width = Ui.S(92);
            send.FlatStyle = FlatStyle.Flat;
            send.FlatAppearance.BorderSize = 0;
            send.BackColor = Theme.Navy;
            send.ForeColor = Color.White;
            send.Cursor = Cursors.Hand;
            send.Font = new Font("Segoe UI Semibold", 9.5f);
            send.Click += delegate { Submit(); };
            input.Dock = DockStyle.Fill;
            input.Font = new Font("Segoe UI", 10.5f);
            input.MaxLength = 1000;
            input.KeyDown += delegate (object s, KeyEventArgs e)
            {
                if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; Submit(); }
            };
            Panel gap = new Panel { Dock = DockStyle.Right, Width = Ui.S(8) };
            foot.Controls.Add(input);
            foot.Controls.Add(gap);
            foot.Controls.Add(send);

            thread.Dock = DockStyle.Fill;
            thread.FlowDirection = FlowDirection.TopDown;
            thread.WrapContents = false;
            thread.AutoScroll = true;
            thread.Padding = new Padding(Ui.S(10), Ui.S(6), Ui.S(10), Ui.S(6));
            thread.SizeChanged += delegate { Relayout(); };

            Controls.Add(thread);
            Controls.Add(foot);
            Controls.Add(status);
            Controls.Add(head);
            Controls.Add(stripe);

            // Pengguna TIDAK bisa mengakhiri percakapan: tombol tutup hanya menyembunyikan jendela ke tray.
            FormClosing += delegate (object s, FormClosingEventArgs e)
            {
                if (sessionActive && e.CloseReason == CloseReason.UserClosing) { e.Cancel = true; Hide(); }
            };
            Shown += delegate { input.Focus(); };
        }

        void Submit()
        {
            string t = input.Text.Trim();
            if (t.Length == 0 || !sessionActive) return;
            input.Clear();
            if (SendRequested != null) SendRequested(t);
        }

        int RowWidth()
        {
            return Math.Max(Ui.S(200), thread.ClientSize.Width - thread.Padding.Horizontal - SystemInformation.VerticalScrollBarWidth - Ui.S(4));
        }

        void Relayout()
        {
            int w = RowWidth();
            foreach (Control row in thread.Controls)
            {
                row.Width = w;
                Panel bubble = row.Controls.Count > 0 ? row.Controls[0] as Panel : null;
                if (bubble != null) bubble.Left = (row.Tag is bool && (bool)row.Tag) ? w - bubble.Width : 0;
            }
        }

        public void AddMessage(ChatMsg m)
        {
            bool mine = m.Sender == "user";
            int w = RowWidth();
            int maxText = (int)(w * 0.78) - Ui.S(24);

            Font textFont = new Font("Segoe UI", 10f);
            Font metaFont = new Font("Segoe UI", 7.5f);
            Label meta = new Label
            {
                Text = (mine ? "Anda" : (string.IsNullOrEmpty(m.Name) ? "IT" : m.Name)) + "   " + m.At.ToString("HH:mm"),
                AutoSize = true, Font = metaFont, Location = new Point(Ui.S(11), Ui.S(5)),
                ForeColor = mine ? Color.FromArgb(200, 210, 235) : Theme.Muted
            };
            Label text = new Label { Text = m.Body, AutoSize = false, Font = textFont, ForeColor = mine ? Color.White : Theme.Ink };
            Size ps = text.GetPreferredSize(new Size(maxText, 0));
            text.Size = new Size(Math.Min(maxText, ps.Width) + Ui.S(2), ps.Height);
            text.Location = new Point(Ui.S(11), meta.Top + metaFont.Height + Ui.S(6));

            Panel bubble = new Panel { BackColor = mine ? Theme.Navy : Color.White };
            bubble.Controls.Add(meta);
            bubble.Controls.Add(text);
            bubble.Size = new Size(Math.Max(text.Width, meta.PreferredWidth) + Ui.S(24), text.Bottom + Ui.S(9));

            Panel row = new Panel { Width = w, Height = bubble.Height + Ui.S(2), Margin = new Padding(0, Ui.S(3), 0, Ui.S(3)), Tag = mine };
            bubble.Left = mine ? w - bubble.Width : 0;
            row.Controls.Add(bubble);
            thread.Controls.Add(row);
            thread.ScrollControlIntoView(row);
        }

        public void SetEnded(string reason)
        {
            sessionActive = false;
            input.Enabled = false;
            send.Enabled = false;
            status.ForeColor = Theme.Important;
            status.Text = reason == "idle" ? "Percakapan ditutup otomatis karena tidak ada aktivitas." : "Percakapan telah diakhiri oleh IT.";
        }

        public bool SessionActive { get { return sessionActive; } }

        protected override bool ShowWithoutActivation { get { return true; } }
    }

    // ---------- Aplikasi tray ----------

    class TrayApp : ApplicationContext
    {
        readonly NotifyIcon tray = new NotifyIcon();
        readonly System.Windows.Forms.Timer timer = new System.Windows.Forms.Timer();
        readonly ApiClient api = new ApiClient();
        readonly Control ui = new Control();
        readonly HashSet<int> shown = new HashSet<int>();
        readonly Queue<Announcement> queue = new Queue<Announcement>();
        readonly HashSet<int> seenMessages = new HashSet<int>();
        readonly ToolStripMenuItem chatItem = new ToolStripMenuItem("Buka chat dengan IT");
        AnnouncementForm current;
        ChatForm chat;
        int sessionId;
        int lastMessageId;
        int failures;
        bool polling;
        string lastProblem = "";

        public TrayApp()
        {
            ui.CreateControl();

            ContextMenuStrip menu = new ContextMenuStrip();
            chatItem.Enabled = false;
            chatItem.Click += delegate { ShowChat(); };
            ToolStripMenuItem about = new ToolStripMenuItem("Tentang SIMA Agent Helper");
            about.Click += delegate
            {
                MessageBox.Show("SIMA Agent Helper\nPT Adhi Kartiko Pratama Tbk\n\nMenampilkan pengumuman dan chat dari tim IT. Tidak membaca file, layar, atau papan klip Anda.\nLog: " + Log.PathName(),
                    "Tentang", MessageBoxButtons.OK, MessageBoxIcon.Information);
            };
            menu.Items.Add(chatItem);
            menu.Items.Add(about);
            tray.ContextMenuStrip = menu;
            try { tray.Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { tray.Icon = SystemIcons.Information; }
            tray.Text = "SIMA Agent Helper";
            tray.Visible = true;
            tray.DoubleClick += delegate { ShowChat(); };

            timer.Tick += delegate { timer.Stop(); PollAsync(); };
            timer.Interval = 5000;     // pemeriksaan pertama sebentar setelah login
            timer.Start();
            Log.Write("Helper mulai");
        }

        void ScheduleNext(int seconds)
        {
            timer.Interval = Math.Max(2, seconds) * 1000;
            timer.Start();
        }

        void PollAsync()
        {
            if (polling) return;
            polling = true;
            int sid = sessionId, after = lastMessageId;
            ThreadPool.QueueUserWorkItem(delegate
            {
                PollResult res = null; Exception err = null;
                try
                {
                    string problem;
                    if (!api.LoadConfig(out problem)) throw new InvalidOperationException(problem);
                    res = api.Poll(sid, after);
                }
                catch (Exception ex) { err = ex; }
                try { ui.BeginInvoke(new MethodInvoker(delegate { polling = false; Apply(res, err); })); }
                catch (InvalidOperationException) { polling = false; }
            });
        }

        void Apply(PollResult res, Exception err)
        {
            if (err != null)
            {
                failures++;
                string msg = err.Message;
                if (msg != lastProblem) { Log.Write("Poll gagal: " + msg); lastProblem = msg; }
                ScheduleNext(Math.Min(120, 20 * failures));        // mundur bertahap: tidak membebani server
                return;
            }
            failures = 0; lastProblem = "";

            foreach (Announcement a in res.Announcements)
            {
                if (shown.Contains(a.Id)) continue;
                shown.Add(a.Id);
                queue.Enqueue(a);
            }
            ShowNextAnnouncement();
            ApplyChat(res);
            ScheduleNext(res.PollSeconds);
        }

        // ---- pengumuman ----

        void ShowNextAnnouncement()
        {
            if (current != null || queue.Count == 0) return;
            Announcement a = queue.Dequeue();
            AnnouncementForm f = new AnnouncementForm(a);
            current = f;
            f.Dismissed += delegate
            {
                bool ack = f.Acknowledged;
                current = null;
                ThreadPool.QueueUserWorkItem(delegate
                {
                    try { if (ack) api.Ack(a.Id); }
                    catch (Exception ex) { Log.Write("Konfirmasi pengumuman #" + a.Id + " gagal: " + ex.Message); }
                });
                f.Dispose();
                ShowNextAnnouncement();
            };
            f.Show();
            if (a.Severity == "urgent") SystemSounds.Exclamation.Play();
            Log.Write("Pengumuman #" + a.Id + " ditampilkan (" + a.Severity + (a.RequireAck ? ", wajib konfirmasi" : "") + ")");
            ThreadPool.QueueUserWorkItem(delegate
            {
                try { api.MarkShown(a.Id); }
                catch (Exception ex) { Log.Write("Penandaan tampil #" + a.Id + " gagal: " + ex.Message); }
            });
        }

        // ---- chat ----

        void ApplyChat(PollResult res)
        {
            if (res.SessionId == 0) return;

            if (res.SessionStatus == "open")
            {
                bool isNew = res.SessionId != sessionId;
                if (isNew)
                {
                    sessionId = res.SessionId; lastMessageId = 0; seenMessages.Clear();
                    if (chat != null) { chat.Dispose(); chat = null; }
                    chat = NewChat(res.StartedBy);
                    Log.Write("Chat dimulai oleh IT (sesi #" + sessionId + ")");
                }
                chatItem.Enabled = true;

                bool fromIt = false;
                foreach (ChatMsg m in res.Messages)
                {
                    lastMessageId = Math.Max(lastMessageId, m.Id);
                    if (!seenMessages.Add(m.Id)) continue;
                    chat.AddMessage(m);
                    if (m.Sender == "it") fromIt = true;
                }
                // Jendela muncul saat sesi dimulai dan setiap ada pesan baru dari IT (tanpa merebut fokus ketikan).
                if (isNew || (fromIt && !chat.Visible)) { ShowChat(); SystemSounds.Asterisk.Play(); }
            }
            else if (res.SessionStatus == "closed" && res.SessionId == sessionId && chat != null)
            {
                foreach (ChatMsg m in res.Messages)
                {
                    lastMessageId = Math.Max(lastMessageId, m.Id);
                    if (seenMessages.Add(m.Id)) chat.AddMessage(m);
                }
                chat.SetEnded(res.CloseReason);
                ShowChat();
                Log.Write("Chat berakhir (" + res.CloseReason + ")");
                ChatForm ended = chat;
                chat = null; sessionId = 0; lastMessageId = 0; chatItem.Enabled = false;
                System.Windows.Forms.Timer t = new System.Windows.Forms.Timer { Interval = 10000 };
                t.Tick += delegate { t.Stop(); t.Dispose(); ended.Close(); ended.Dispose(); };
                t.Start();
            }
        }

        ChatForm NewChat(string startedBy)
        {
            ChatForm f = new ChatForm(startedBy);
            int sid = sessionId;
            f.SendRequested += delegate (string text)
            {
                ThreadPool.QueueUserWorkItem(delegate
                {
                    ChatMsg sent = null; string error = null;
                    try { sent = api.SendChat(sid, text); if (sent == null) error = "closed"; }
                    catch (Exception ex) { error = ex.Message; Log.Write("Balasan chat gagal: " + ex.Message); }
                    try
                    {
                        ui.BeginInvoke(new MethodInvoker(delegate
                        {
                            if (chat != f) return;
                            if (sent != null) { if (seenMessages.Add(sent.Id)) f.AddMessage(sent); }
                            else if (error != "closed") f.AddMessage(new ChatMsg { Id = -Environment.TickCount, Sender = "it", Name = "Sistem", Body = "Pesan belum terkirim. Coba lagi sebentar." });
                            timer.Stop(); PollAsync();       // ambil balasan lebih cepat
                        }));
                    }
                    catch (InvalidOperationException) { }
                });
            };
            return f;
        }

        void ShowChat()
        {
            if (chat == null) return;
            if (!chat.Visible) chat.Show();
            if (chat.WindowState == FormWindowState.Minimized) chat.WindowState = FormWindowState.Normal;
            chat.Activate();
        }

        protected override void ExitThreadCore()
        {
            tray.Visible = false;
            tray.Dispose();
            base.ExitThreadCore();
        }
    }

    static class Program
    {
        [STAThread]
        static void Main()
        {
            bool created;
            using (Mutex single = new Mutex(true, @"Local\SIMA-Agent-Helper", out created))
            {
                if (!created) return;
                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                Application.ThreadException += delegate (object s, ThreadExceptionEventArgs e) { Log.Write("Kesalahan UI: " + e.Exception.Message); };
                Application.Run(new TrayApp());
            }
        }
    }
}
