<x-mail::message>
# Undangan bergabung

@if ($inviterName)
{{ $inviterName }} mengundang kamu untuk bergabung ke **{{ $companyName }}** di Finlite.
@else
Kamu diundang untuk bergabung ke **{{ $companyName }}** di Finlite.
@endif

Buka tautan di bawah, lalu buat kata sandi (jika belum punya akun) atau masuk dengan akun yang sudah ada. Undangan ini hanya berlaku untuk alamat email ini.

<x-mail::button :url="$acceptUrl">
Terima undangan
</x-mail::button>

Tautan berlaku sampai {{ $expiresAt }}. Jika kamu tidak merasa diundang, abaikan email ini.
</x-mail::message>
