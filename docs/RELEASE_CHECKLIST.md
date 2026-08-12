# Tjekliste før offentlig lancering

## Verificeret i kode og test

- Frontend lint, TypeScript-kontrol og produktionsbygning er grøn.
- Laravel har grønne featuretests, og Composer har ingen kendte sikkerhedsadvarsler.
- Web- og API-containeren bygger til produktion.
- Login og rettigheder håndhæves server-side; browseren får ikke et teknisk API-token.
- Eksisterende administrator og produktionsdata overskrives ikke ved udrulning.
- Privat booking, branchekundeportal og medarbejdervisning er kontrolleret på mobil og desktop.
- Pauser og faste buffertider indgår særskilt i kapacitetsberegningen.
- Kunde kan finde, ændre og afbestille via et tidsbegrænset administrationslink.
- Automatiske forslag til danske helligdage kan gennemgås før de oprettes som lukkedage.
- Databasemigreringer kører før API, kø og scheduler starter.
- MySQL og API er ikke eksponeret direkte; trafik går gennem Nginx.

## Skal være udfyldt på NAS før offentlig trafik

- Sæt en fast offentlig HTTPS-adresse i `APP_URL` og `FRONTEND_URL`.
- Sæt `SESSION_SECURE_COOKIE=true`, når HTTPS er aktivt.
- Konfigurér SMTP (`MAIL_*`) og gennemfør en rigtig glemt-adgangskode-mail.
- Behold `SEED_DEMO_DATA=false`; testdata må ikke være i produktionsdatabasen.
- Kontrollér DMR-forbindelsen fra NAS'en og et manuelt fallback-opslag.
- GatewayAPI/SMS er fortsat slukket, indtil udbydertoken, afsender, databehandlerforhold og en afgrænset testudsendelse er godkendt.
- Dinero og øvrige skriveintegrationer er fortsat slukket, indtil deres særskilte idempotens- og rollbacktest er godkendt.
- Kør første backup og en restore-test, før systemet annonceres som produktionsklart.
- Aktivér kryptering og begrænset adgang på NAS-volumen og backupmålet, da kundernes nødvendige kontaktdata ligger i MySQL.
- Verificér DNS, TLS-certifikat, overvågning og adgangslog uden følsomme data.

## Efter hver udgivelse

1. Kontrollér `/api/health` og Drift-siden.
2. Log ind som medarbejder med begrænsede rettigheder og som ejer.
3. Opret og afbestil én privat testbooking.
4. Opret og afbestil én branchekundebooking.
5. Kontrollér kapacitet, fast buffer og pause på samme dag.
6. Kontrollér at mail/SMS kun sendes i de miljøer, hvor de er aktiveret.
7. Kontrollér seneste backup og registrér udgivelsens Git-commit.
