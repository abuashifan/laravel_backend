<x-mail::message>
# Satu langkah lagi

Halo {{ $name }}, terima kasih sudah mendaftar Finlite Kasir.

Ketuk tombol di bawah untuk memastikan email ini milikmu. Setelah itu masa coba gratis dimulai dan kamu bisa membuat toko.

<x-mail::button :url="$verifyUrl">
Verifikasi email
</x-mail::button>

Tautan berlaku {{ $validHours }} jam. Jika kamu tidak merasa mendaftar, abaikan email ini.
</x-mail::message>
