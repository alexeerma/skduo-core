# DataProjecti mängude ühendus

Seadistus asub **Mängud → DataProject**. Ühendus teeb DataProjecti API-s ainult GET-päringuid; see ei muuda liiga andmeid.

2026-10-13 päris API vastustega kontrollitud valik:

| Seade | Väärtus |
| --- | --- |
| Organisatsioonikood | BVL |
| Hooaeg | BVL 2026/2027, SeasonID 10 |
| Võistlus | Elme Messer Men`s Baltic League 2026/2027, CompetitionID 29 |
| Meeskond | BIGBANK Tartu, TeamID 213 |
| Ilma ajavööndita API kellaaegade ajavöönd | Europe/Tallinn |

API tagastas valitud meeskonnale kaheksa mängu. See on API hetkeandmestik, mitte kinnitus kogu hooaja graafiku täielikkuse kohta. Hooaja vahetamisel vali API-st uus hooaeg, võistlus ja meeskond: meeskonna ID muutub hooajati (2025/2026 Bigbank Tartu ID oli 183).

## Seadistamine uues serveris

1. Paigalda uuendatud SK DUO Core plugin ja teema.
2. WordPressi **Seaded → Üldine → Ajavöönd**: `Europe/Tallinn`.
3. Sisesta **Mängud → DataProject** lehel organisatsioonikood ja Bearer token ilma `Bearer` eesliiteta. Tühjaks jäetud token'i väli säilitab olemasoleva token'i. Kasutajanimi ei asenda token'it.
4. Salvesta, vajuta **Laadi valikud**, vali hooaeg, salvesta ja laadi valikud uuesti. Vali meeste Balti liiga ning BIGBANK Tartu ja salvesta. Ülaltoodud kontrollitud ID-d saab sisestada ka otse.
5. Vajuta **Sünkrooni mängud** ja kontrolli kalendrit. Seejärel võid lubada tunnise uuendamise.

Token salvestatakse WordPressi andmebaasi valikuna, mida ei laadita automaatselt ega tagastata lehe HTML-is või avalikus REST API-s. Alternatiivina saab selle määrata serveri `wp-config.php` failis konstandina `SKDUO_DATAPROJECT_TOKEN`; konstant on andmebaasi valikust prioriteetsem. Ära lisa token'it teemasse, pluginasse ega ZIP-i. Paigalduspakett ei sisalda kohaliku saidi token'it ega mängude andmebaasi.

WordPressi ajastaja sõltub lehe külastustest. Täpse graafiku jaoks käivita serveri ajastajast WordPressi cron regulaarselt. Plugina deaktiveerimine eemaldab selle sünkroonimise ajastuse.

## Importimise käitumine

- Päringute ahel: Season / Team → Competition → Phase → Championship → Leg → Match. Tühi saalinimi täidetakse StadiumID abil Stadium-päringust.
- Meeskond tuvastatakse HomeTeamID / GuestTeamID järgi. Võistluse ja hooaja ID-sid kontrollitakse enne salvestamist.
- Kohalik kirje seotakse `organisatsioonikood:MatchID` võtmega. Kordusimport uuendab sama kirjet; olemasolevat mängu sisu ja piletiviita ei muudeta.
- API päringute tulemused kontrollitakse täielikult enne kohalike mängude muutmist. HTTP-, JSON-, ID- ja kuupäevaviga peatab impordi. Andmebaasi kirjutusvea korral võib osa kirjeid olla juba salvestatud; uus käivitus kasutab samu ID-sid.
- API ei dokumenteeri arvulise Status välja tähendust. Import säilitab selle metaandmetes ning kasutab lõppenud mängu jaoks ainult tõeväärtust Finalized. Live-olekut ega tühistamist ei oletata arvulisest koodist.
- Ilma ajavööndita kuupäev tõlgendatakse valitud lähteajavööndis. UTC või nihkega kuupäev teisendatakse WordPressi ajavööndisse. Ajavöönd on halduses muudetav; API Match-vastuse lisaväli StadiumTimeZone ei ole üldises PDF lepingus määratletud.
- Kuupäevata mäng salvestatakse mustandina; olemasolev vana kuupäev eemaldatakse, kui API tagastab kuupäeva puudumise. Uue kinnitatud kuupäevaga avaldatakse mäng uuesti.
- API-st kadunud mänge automaatselt ei kustutata. Prügikasti pandud imporditud mängu ei taastata.
- Varem käsitsi sisestatud mänge ei seostata API mängudega automaatselt. Kontrolli esimese impordi järel võimalikke sisulisi duplikaate.
- Sünkroonimise lukk väldib samaaegseid importe. Katkenud töö luku saab halduses vabastada 30 minuti möödudes.

## Kontrollimine

```sh
php tests/dataproject-test.php
```

Offline-testid ei kasuta päris andmebaasi ega ligipääsuandmeid. Kontrollitud on meeskonna filtreerimine, kodu/võõrsil, kordusimport, ajamuutus, tulemused, piletiviida säilitamine, saali päring, ajavööndi teisendus, API tõrked, vigased ID-d ning sünkroonimise lukk.

## EVF / Aadu Luukase karikas

Alates versioonist 1.0.3 on **Mängud → DataProject** lehel kaks ühenduse vahekaarti: **Balti liiga** ja **EVF / karikavõistlused**. Mõlemal on oma token, hooaja/võistluse/meeskonna valikud, automaatse uuendamise lüliti ja viimase sünkroonimise tulemus. Varasema BVL seadistuse andmed säilivad uuendamisel.

2026-10-13 EVF konto token'iga API-st kontrollitud:

| Seade | Väärtus |
| --- | --- |
| Organisatsioonikood | EVF |
| Hooaeg | EVF 2026/2027, SeasonID 10 |
| Võistlus | Aadu Luukase karikas 2026, CompetitionID 100 |
| Meeskond | BIGBANK Tartu, TeamID 777 |

EVF token tuleb sisestada **EVF / karikavõistlused** vahekaardil, mitte BVL token'i asemele. Serveri konfiguratsioonis saab alternatiivina määrata `SKDUO_DATAPROJECT_EVF_TOKEN`. BVL jätkab varasema `SKDUO_DATAPROJECT_TOKEN` konstandi kasutamist. Mõlemad token'id tuleb live-saidil eraldi seadistada; need ei sisaldu ZIP-is.

API tagastas kontrollimise ajal ühe Bigbank Tartu karikamängu: Structura/Tartu vald vs BIGBANK Tartu, 25.10.2026 kell 16.00, Kõrveküla kooli SH (MatchID 3612). Järgmised karikamängud lisanduvad API-sse avaldamise järel järgmise sünkroonimisega.

Mängu identiteet sisaldab organisatsioonikoodi (`BVL:...` või `EVF:...`), nii et eri organisatsioonide kattuvad numbrilised ID-d ei tekita konflikti. Ajastaja uuendab iga lubatud ühendust eraldi; ühe ühenduse API tõrge ei takista teise ühenduse importi. Käsitsi sünkroonimise nupp uuendab parajasti avatud vahekaardi ühendust.

## Turvameetmed ja API logi (1.0.4)

- Andmebaasis olevad Bearer token'id krüpteeritakse AES-256-GCM algoritmiga, kasutades WordPressi AUTH salt'ist tuletatud võtit. Igal salvestamisel on uus juhuslik IV ning autentimistag kaitseb muutmise eest. Krüpteeritud väärtus on seotud BVL-i või EVF-i ühendusega. Senised token'id migreeritakse automaatselt lugemisel. Token'ite sisu ei muutu.
- Kui WordPressi AUTH võtmed/salt'id muutuvad või andmebaas viiakse teise serverisse, tuleb andmebaasis olevad token'id uuesti sisestada. Hoia andmebaasi ja serveri konfiguratsiooni varukoopiaid turvaliselt. Serveri konfiguratsioonis määratud token-konstandid töötavad jätkuvalt.
- API päringud kasutavad fikseeritud HTTPS hosti, WordPressi turvalist HTTP klienti, sertifikaadi kontrolli, null ümbersuunamist, 20-sekundilist tähtaega ja 4 MB vastusepiirangut. Ainult dokumenteeritud ressursid ning positiivsete numbriliste ID-dega filtrid on lubatud.
- Haldustoimingud nõuavad administraatori `manage_options` õigust, POST-meetodit ja korrektset WordPressi nonce'i. Vigased ühendusenimed ja struktureeritud vormiväljad lükatakse tagasi.
- Halduses saab valikute laadimist või sünkroonimist käivitada ühe ühenduse kohta kõige sagedamini kord 60 sekundi jooksul. HTTP 429 korral arvestab klient Retry-After päisega: 60–3600 sekundit. Ooteaeg on ühendusepõhine ning ei takista teise ühenduse tööd.
- Ootamatu erand lõpetab impordi üldise veateatega ja vabastab sünkroonimise luku. Toor-erandit ega API vastuse sisu logisse ei salvestata.

**Mängud → DataProject → API logi** näitab valitud ühenduse viimast kuni 200 sündmust viimase 7 päeva jooksul. Kirjes on aeg, päringu ressurss ja numbrilised filtrid, HTTP kood, kestus millisekundites, vastuse kirjete arv ning sünkroonimise lisatud/uuendatud mängude arv või veakood. Vanemad logikirjed eemaldatakse järgmise logikirje salvestamisel. Logi on WordPressi andmebaasis, autoload on välja lülitatud; avalikku logifaili ega REST logi endpoint'i ei ole.

Token'eid, HTTP päiseid, toorvastuseid, mängijate/kohtunike isikuandmeid ega suvalisi veateateid logisse ei salvestata. Väljad on lubatud loendiga piiratud ning halduse HTML-is escapitud.

Kontrollitud 2026-10-13: 96 offline-kontrolli; mõlema päris API kordusimport (BVL 8 uuendatud, EVF 1 uuendatud, uusi duplikaate 0); krüpteeritud token'ite migratsioon; administraatori logivaade ja token'ite puudumine HTML-is; anonüümse logi ligipääsu keelamine; vormi õigused, meetod, sisendtüübid ning nonce'i kontroll. Need kontrollid ei asenda live-serveri konfiguratsiooni ja paigalduse kontrolli.

## Seadete salvestamise parandus (1.0.5)

Organisatsioonikoodi või hooaja muutmine ei nulli enam samas vormis sisestatud hooaja, võistluse ja meeskonna ID-sid. Muutunud konteksti korral tühjendatakse üksnes vanad API valikute vahemälud; ID-de kuuluvust kontrollitakse endiselt enne importi. Päris kohaliku WordPressi salvestuskäitlejaga kontrollitud kuus juhtumit: BVL/EVF esmane seadistus, hooaja muutmine ja kordussalvestus. Lisaks läbisid 96 offline-kontrolli. Kohalik regressioonitest `tests/admin-save-regression.php` nõuab selgesõnalist `--allow-local-option-test` argumenti ja taastab pärast testi varasemad seaded; ära käivita seda live-serveris.

## Valikuline uuendamine ja statistikalink (1.0.7)

- Käsitsi ja automaatne sünkroonimine kasutavad sama väljade kaupa uuendamist. Uus API mäng lisatakse; muutumata mängu postitust ega metaandmeid ei salvestata uuesti. Logis on nüüd ka muutumatute mängude arv.
- API-st uuenevad tulemus, mänguolek ja allika tehnilised ID-d. API lähteteksti, saali, mänguaja ja liiga viimased väärtused salvestatakse võrdluseks eraldi metaandmetesse. Need väljad uuenevad järgmisel impordil ainult juhul, kui kohalik väärtus vastab viimasele API väärtusele. Käsitsi muudetud ja pärast importi teadlikult tühjendatud väljad säilivad.
- Varem imporditud mängude puhul, millel API võrdlusandmed puuduvad, säilitatakse esimesel uuendamisel olemasolevad mittetühjad muudetavad väljad. Nende käsitsi/API päritolu ei saa tagantjärele kindlalt taastada. Tulemuse ja statistikalingi uuendamine töötab ka nende mängudega.
- Olemasoleva mängu pealkirja, sisu, piletilinki, tasuta sissepääsu märget, pilti ja muid toimetatud andmeid import ei muuda. Käsitsi mustandiks tehtud mängu ei avaldata uuesti. Importija kuupäevapuuduse tõttu loodud mustand avaldatakse kuupäeva kinnitamisel nagu varem.
- Statistikalingi alus on DataProjecti avalik `MatchStatistics.aspx?ID={CompetitionID}&mID={MatchID}` leht. BVL ja EVF kasutavad oma domeeni. See on veebistatistika, mitte DataVolley `.dvw` faili allalaadimine. Link kuvatakse kalendris ja mängu detailvaates pärast mänguaega või lõpetatud oleku puhul.
- Mängu halduses saab „DataProjecti mängu statistika link” väljal lingi muuta. Käsitsi muudetud või eemaldatud linki järgnev sünkroonimine üle ei kirjuta.

Kontrollitud 2026-10-28: 114 offline-kontrolli, mõlema päris API kordusimport (BVL 8 muutumatut, EVF 1 muutumatu; 0 uut ja 0 uuendatud), olemasoleva toimetatud sisu ning piletite/tasuta sissepääsu säilimine; tulemused 3:0 / 0:3 ja mõlema allika linkide kuvamine kohalikus kalendris. Veebilingi nupp vajab ka uuendatud SK DUO teemat 11.3.12.

## Valikute laadimise järel kohe sünkroonimine (1.0.8)

Valikute laadimisel ja mängude sünkroonimisel on nüüd eraldi 60-sekundilised piirangud. Vajutamine **Laadi valikud → Sünkrooni mängud** ei blokeeri enam mängude importi. Sama nupu korduv vajutamine on endiselt piiratud ja HTTP 429 ooteaeg kehtib endiselt kogu ühendusele. Logi Season/Competition/Team päringud tähendavad ainult valikute laadimist; mängude import lisab Phase/Championship/Leg/Match päringud ja Sünkroonimise kokkuvõtte.

Kui API lähteajavöönd on Europe/Tallinn, kuid WordPressi ajavöönd UTC, näitab haldus nüüd hoiatust ja linki **Seaded → Üldine** lehele. Eesti kalendri jaoks vali WordPressi ajavööndiks Tallinn/Europe/Tallinn; ära määra püsivat UTC+2 nihet, sest see ei arvesta suveaega. Seejärel sünkrooni uuesti. Varem käsitsi muudetud või API võrdlusandmeteta säilitatud vana mänguaeg ei pruugi automaatselt muutuda ja vajab eraldi kontrollimist. Plugin ei nihuta kõiki kohalikke mänguaegu pimesi kahe tunni võrra.

119 offline-kontrolli läbisid testi, sh valikute laadimisele kohe järgnev edukas sünkroonimine ja sama sünkroonimisnupu korduspäringu blokeerimine.

## Mängude haldusloendi kuupäevafilter (1.0.9)

**Mängud → Kõik mängud** näitab nüüd pealkirja kõrval veergu **Mängu kuupäev**. Vaikimisi järjestatakse loend mängu enda kuupäeva järgi kasvavalt. Veeru pealkirjal klõpsates saab valida kasvava või kahaneva järjestuse. Kuupäevata mängud jäävad mõlema järjestuse korral lõppu; pealkirja ja avaldamise kuupäeva järgi sortimine on endiselt võimalik.

Ülemises filtrireas on **Mängud alates** ja **kuni** kuupäevaväljad. Sisesta üks või mõlemad piirid ja vajuta WordPressi **Filtreeri** nuppu. Mõlemad kuupäevad on kaasa arvatud; ühe päeva otsinguks sisesta mõlemasse välja sama kuupäev. **Eemalda filtrid** avab mängude kogu loendi. Vigase või tagurpidi vahemiku korral kuvatakse veateade ja tühi tulemus, mitte kogemata kogu loend.

Teostus asub `includes/match-admin.php` failis ja puudutab ainult mängude haldusekraani põhipäringut. Avaliku kalendri, REST päringute ega API sünkroonimise järjestust ei muudeta. Kontrollitud 13 kohaliku WordPressi integratsioonitestiga (ajutised mustandid eemaldati); olemasolevad 119 DataProjecti kontrolli läbisid samuti testi. Teema uuendamist see muudatus ei vaja.
