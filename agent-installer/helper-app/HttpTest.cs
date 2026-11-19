// Alat uji (BUKAN bagian produk): memanggil ApiClient ke server tiruan lewat HTTP sungguhan.
// Dikompilasi dengan /main:Akp.SimaAgentHelper.HttpTest.
using System;

namespace Akp.SimaAgentHelper
{
    static class HttpTest
    {
        static int Main(string[] args)
        {
            ApiClient api = new ApiClient();
            api.Configure(args[0], args[1]);

            PollResult r = api.Poll(7, 3);
            Console.WriteLine("poll: announcements=" + r.Announcements.Count + " session=" + r.SessionId + " messages=" + r.Messages.Count + " poll_seconds=" + r.PollSeconds);

            api.MarkShown(11);
            Console.WriteLine("shown ok");
            api.Ack(11);
            Console.WriteLine("ack ok");

            ChatMsg sent = api.SendChat(7, "Halo, ini balasan pengguna — dengan \"kutip\" dan emoji 🙂");
            Console.WriteLine("chat kirim: " + (sent == null ? "NULL" : "id=" + sent.Id + " sender=" + sent.Sender));

            ChatMsg closed = api.SendChat(999, "ke sesi tertutup");
            Console.WriteLine("chat ke sesi tertutup (409): " + (closed == null ? "null (benar)" : "SALAH"));
            return 0;
        }
    }
}
