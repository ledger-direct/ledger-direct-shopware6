# 1.8.0
- Store-API für Headless-Frontends, erster Teil: `GET /store-api/ledger-direct/payment/quote/{orderId}`
  antwortet einem Gast mit dem `deepLinkCode` der Bestellung, wie `check` es immer getan hat, statt einen
  angemeldeten Kunden zu verlangen. Ein falscher Code und die Bestellung eines anderen Kunden bekommen
  dasselbe 403, eine nie mit LedgerDirect quotierte Bestellung 404, ein abgelaufenes Angebot oder eine Bestellung, die nicht mehr auf eine Zahlung wartet, 410 (bisher 500)
- Die `quote`-Nutzlast bekommt `page` (`schema_version` 1): jeden Wert, aus dem die Zahlungsseite
  aufgebaut wird, für XRP, RLUSD, USDC und die Stellar-Assets gleichermaßen — Zustand, Restlaufzeit, Beträge
  wie die Seite sie zeigt, Konto, Tag bzw. Memo, Herausgeber und Währungscode, die Zahlungsanfrage für den
  QR-Code, Explorer-Basis und die Kennungen der Wallet-Apps. Die Zahlungsseite der Storefront rendert aus
  derselben Wertemenge (`PaymentPageView`), die beiden können also nicht auseinanderlaufen. Die flachen
  Felder von `quote` bleiben, wie sie waren
- Die Route `price` ist entfernt; sie war seit der ersten Version ein Platzhalter mit der Antwort
  `{"todo": "implement"}`. Was sie liefern sollte, liefert `quote`
- README: ein Abschnitt „Headless / externes Frontend“ zur Store-API und zur `headers`-Option des
  Zahlungsseiten-Pakets

# 1.7.0
- Stellar als zweite Chain, **als Vorschau** (Core 0.10): Zahlarten XLM, USDC und EURC, bezahlt per
  Memo-ID, über Horizon synchronisiert, nach denselben Regeln abgewickelt wie auf dem XRP Ledger. Eine
  Karte „LedgerDirect – Stellar (Vorschau)" in der Konfiguration, standardmäßig aus; sonst zeigt sich
  nichts von Stellar, bis sie an ist. Das Einschalten legt die Stellar-Zahlarten an, die im Checkout
  erscheinen, sobald ein Empfangskonto hinterlegt ist. Noch nicht für den Produktivbetrieb
- Ein herausgegebenes Stellar-Asset (USDC, EURC) wird im Checkout nur angeboten, solange das Empfangskonto
  eine Trustline zu seinem Herausgeber hat; ohne sie würde die Zahlung in der Wallet des Kunden scheitern
- Die Zahlungsseite sagt auf Stellar „Memo-ID" statt „Destination Tag", der QR-Code trägt eine
  SEP-7-Zahlungsanfrage, die Transaktion verlinkt auf stellar.expert. Die Browser-Wallet-Sektion gibt es
  nur auf dem XRPL (Zahlungsseiten-Paket 0.2.0)
- Die LedgerDirect-Karte in der Bestelldetailansicht zeigt bei einer Stellar-Bestellung Memo-ID und
  stellar.expert-Link
- Konsolenbefehle nehmen `--chain XRPL|STELLAR`; die Preisabfrage nimmt `--asset`
- Zwei neue Tabellen, `ledger_direct_stellar_payment` und `ledger_direct_stellar_memo`, werden beim Update
  angelegt

# 1.6.0
- Die Konfiguration ist auf mehrere Chains ausgelegt. Die Karte „XRP Ledger" bekommt den Schalter
  „Zahlungen über das XRP Ledger annehmen" (an bei jeder Installation mit hinterlegter
  Empfangsadresse); eine zweite Chain bekommt eine eigene Karte gleichen Aufbaus. Nichts wurde
  umbenannt, keine Einstellung muss neu eingetragen werden
- Zahlarten, deren Chain ausgeschaltet ist, deren Empfangsadresse fehlt oder deren Asset deaktiviert
  ist, erscheinen nicht mehr im Checkout. Bisher wurde ein deaktivierter Stablecoin weiter angeboten
  und scheiterte erst, wenn der Kunde ihn wählte
- Eine Empfangsadresse wird beim Speichern geprüft: eine Adresse, die kein Konto auf dieser Chain
  ist, wird mit Hinweis abgelehnt; Leerzeichen oder Zeilenumbrüche um eine eingefügte Adresse werden
  entfernt
- Core 0.10: Zahlarten, ihre Assets und das Adressformat der Chain kommen aus dem `ChainCatalog` des
  Cores; das Plugin führt keine eigene Liste mehr. Intern ein Zahlungs-Handler je Chain statt je Asset;
  die drei XRP-Ledger-Zahlarten behalten IDs, Namen und Handler-Kennungen
- Core 0.10: eine Zahlung auf dem anderen Netz — eine Testnet-Zahlung für eine Mainnet-Bestellung —
  begleicht nie eine Bestellung und erscheint nie als Falsch-Token-Zahlung
- Vorbereitung für Stellar (XLM, USDC, EURC), das mit 1.7.0 folgt

# 1.5.2
- Store-Validierung: Eine Migration liest einen Spaltennamen nicht mehr über eine Doctrine-DBAL-Klasse,
  die Shopware 6.8 als veraltet markiert. Keine Verhaltensänderung

# 1.5.1
- Core 0.9: Die synchronisierten Transaktionen speichern die CTID in der Spalte `tx_ctid` statt
  `ctid`, dem Namen, den ab jetzt alle LedgerDirect-Plugins verwenden (PostgreSQL reserviert `ctid`
  für eine Systemspalte). Eine Migration benennt die Spalte beim Update um; für Kunden und Händler
  ändert sich nichts
- Core 0.9: Eine Transaktion mit fehlerhafter CTID wird beim Synchronisieren übersprungen und
  protokolliert, statt gekürzt gespeichert zu werden

# 1.5.0
- Eine LedgerDirect-Karte im Bestelldetail (Tab Details, unter der Transaktionskarte): Zustand der
  Zahlung, was quotiert wurde (Asset, Betrag, Kurs, Empfangskonto, Destination Tag, Herausgeber,
  Kursgültigkeit), was eingegangen ist — bei falschem Token mit dessen Namen und Herausgeber —, was
  noch offen ist, und die Transaktion als Link zum XRPL-Explorer. Nur lesend; der Server berechnet
  alles über die neue Admin-Route `/api/_action/ledger-direct/payment-info/{orderTransactionId}`
  (`order:read`)
- Der Scheduled Task synchronisiert das konfigurierte Empfangskonto bei jedem Lauf, sodass auch eine
  Zahlung auf eine bereits stornierte Bestellung erfasst wird

# 1.4.4
- Core 0.8.1: Eine Zahlung in der anderen Asset-Klasse — ein Token für eine XRP-Bestellung oder XRP für
  eine Token-Bestellung — wird als Zahlung im falschen Token mit dem vollen Restbetrag gemeldet
  (Transaktion `paid_partially`), statt ignoriert zu werden. Bisher blieb die Seite auf „Warten", während
  das Geld auf dem Ledger lag

# 1.4.3
- Core 0.8: Die Zahlungsanfrage hinter dem QR-Code (`PaymentUri`) und die Regel für die Akzentfarbe
  (`AccentColor`) kommen jetzt aus der geteilten Core-Bibliothek; die Kopien im Plugin sind weg. Für
  Kunden und Händler ändert sich nichts

# 1.4.2
- Verhalten und Design der Zahlungsseite kommen jetzt aus dem geteilten Paket `@ledger-direct/payment-ui`
  (0.1.1), demselben Code, den jedes LedgerDirect-Plugin rendert; die lokale Kopie ist weg. Die Wurzel der
  Seite trägt den geforderten Betrag als `data-ld-amount-requested`; die alten IDs `xrp-amount`,
  `token-amount`, `destination-account` und `destination-tag` entfallen. Für Kunden ändert sich nichts

# 1.4.1
- Aufräumen nach 1.4.0: tote Dateien entfernt (Cypress-Reste, eine unbenutzte Exception-Klasse),
  Lint-Warnungen in den Skripten der Zahlungsseite bereinigt, das Store-Zip liefert das committete
  Storefront-Bundle unverändert aus, statt es ein zweites Mal zu bauen. Keine Verhaltensänderung

# 1.4.0
- Die Zahlungsseite ist neu gestaltet: Der zu sendende Betrag ist das Größte auf der Seite und
  hat einen Kopier-Button, der Fiat-Betrag steht darunter; Empfängeradresse, Destination Tag (als
  Pflicht markiert, mit dem Hinweis direkt darunter) und bei Tokens der Herausgeber sind
  nummerierte Felder mit Kopier-Buttons; Countdown in Minuten und Sekunden mit Balken; eine Spalte
  auf dem Handy; Dunkelmodus folgt dem System; keine Webschrift wird geladen
- Ein QR-Code mit Empfängeradresse, Destination Tag und Betrag (bei Tokens auch Währung und
  Herausgeber) — das Abtippen des Tags war die größte Fehlerquelle. Mit Xaman am Testnet
  verifiziert: Adresse, Tag und Betrag werden wie angezeigt übernommen
- Browser-Wallets über XRPL Connect: Crossmark, GemWallet, MetaMask Snap, Ledger, Otsu und Xyra
  werden angeboten, wenn sie erkannt werden; Xaman und WalletConnect, wenn der Händler ihre
  öffentliche Kennung in der neuen Konfigurationskarte „Zahlungsseite" einträgt. Die
  Wallet-Bibliothek wird erst beim Öffnen der Wallet-Liste geladen
- Neue Konfigurationskarte „Zahlungsseite": Shop-Logo, ein Bild aus der Medienverwaltung oder
  ein Monogramm; eine Akzentfarbe (eine zu helle Farbe für weiße Schrift fällt auf den Standard
  zurück)
- Eine Teilzahlung macht den Restbetrag zum Hauptbetrag mit Fortschrittsbalken; ein abgelaufener
  Betrag ist durchgestrichen und nicht kopierbar; eine eingegangene Zahlung zeigt eine
  Bestätigung vor der Weiterleitung
- Behoben: Der Herausgeber wurde nie angezeigt, obwohl der Hinweis zum falschen Token darauf
  verwies; der Destination-Tag-Hinweis sagte bei jedem Asset „XRP"; der Kurs stand verkehrt herum
- Der Prüfen-Button funktioniert ohne JavaScript
- Die Zahlungsart-Icons im Checkout existieren jetzt; der verirrte Link „Zahlungsart ändern" ist
  weg
- Verhalten und Design der Seite sind frameworkfrei (payment-ui/) und werden mit den anderen
  LedgerDirect-Plugins geteilt

# 1.3.0
- Gastbestellungen können bezahlt werden: Zahlungsseite und Zahlungsstatus-Endpunkt verlangen
  keinen Login mehr. Der Link-Code der Bestellung — derselbe wie hinter dem Gast-Bestelllink in der
  Bestätigungsmail — öffnet sie, sodass ein Gast nach der Bestellung die Zahlungsanweisung sieht
  statt einer Login-Seite, und derselbe Link eine Stunde später in einem neuen Browser noch geht
- Die Zahlungsseite zeigt einen von fünf Zuständen — wartend mit Countdown, abgelaufen mit
  Schaltfläche für einen aktualisierten Betrag, teilweise bezahlt, im falschen Token bezahlt,
  bezahlt — und fragt den Status alle acht Sekunden ab; sie lädt nicht mehr neu und verlässt sich
  erst, wenn die Bestellung bezahlt oder geschlossen wurde
- Zwei Teilzahlungen addieren sich: Wer den Restbetrag nachsendet, begleicht die Bestellung
- Der Händler sieht eine Teilzahlung oder eine Zahlung im falschen Token sofort als „Teilweise
  bezahlt" in der Administration und „Bezahlt", sobald die Bestellung beglichen ist — dafür muss
  der Kunde nicht mehr in den Shop zurückkehren, und ein zwischenzeitlich abgelaufenes
  Zahlungs-Token lässt eine bezahlte Bestellung nicht mehr offen
- Der Abgleich mit dem Ledger läuft höchstens alle fünf Sekunden je Empfangskonto, egal wie viele
  Kunden auf ihrer Zahlungsseite warten
- Der Status-Endpunkt antwortet mit derselben Nutzlast wie alle anderen LedgerDirect-Plugins
  (`schema_version`, `state`, `base_asset`, `amount_requested`, `amount_paid`, `shortfall`,
  `seconds_left`) plus `redirect`, sobald die Bestellung nicht mehr wartet
- Benötigt hardcastle/ledger-direct-core 0.7

# 1.2.0
- Destination-Tags starten je Empfangskonto an einer zufälligen Stelle statt immer bei null; zwei
  Shops auf derselben Wallet vergeben damit nicht länger dieselben Tags — bisher konnte so die
  Zahlung des einen Shops die Bestellung des anderen bezahlen
- Welche Transaktion auf einem Destination-Tag eine Bestellung bezahlt, entscheidet jetzt die
  Asset-Klasse statt der Zeilenreihenfolge: eine Fremdzahlung im anderen Asset blockiert nicht mehr
  die echte
- Der Sync-Cursor wird je Empfangskonto und Netzwerk geführt und erholt sich nach einem Reset des
  XRPL-Testnets selbst, statt bei jedem Sync zu scheitern, bis die Tabelle von Hand geleert wird
- Die Zahlungsseite erklärt jetzt eine eingegangene Zahlung, die die Bestellung nicht begleicht —
  falscher Herausgeber oder zu geringer Betrag — samt offenem Restbetrag
- Betrag auf der Zahlungsseite korrigiert: die Handlungsaufforderung rundete die Quote auf zwei
  Nachkommastellen, obwohl sie mit fünf ausgewiesen wird — wer genau den angezeigten Betrag zahlte,
  konnte damit unterzahlen und die Bestellung blieb offen, besonders bei kleinen Bestellwerten
- Deutsche Zahlungsseite korrigiert: dort stand der Platzhalter statt des Betrags
- Benötigt hardcastle/ledger-direct-core 0.4

# 1.1.0
- Shopware-6.7-Kompatibilität: Payment-Handler auf die neue `AbstractPaymentHandler`-API migriert
- Doctrine-DBAL-4-Parametertypen korrigiert und entferntes `fetchAll()` durch `fetchAllAssociative()` ersetzt
- Storefront-Controller korrigiert (veralteten `setTwig`-Service-Aufruf entfernt)
- Import der QR-Code-Bibliothek im Storefront-JavaScript korrigiert
- Zahlungs-Metadaten korrigiert: `base_asset` und `quote_currency` ergänzt, `pairing` spiegelt jetzt das tatsächliche Asset
- Ungenutzten `TokenPaymentHandler` entfernt
- Preisermittlung, XRPL-Zugriff, Transaktions-Sync und Destination-Tags kommen jetzt aus der
  gemeinsamen Bibliothek `hardcastle/ledger-direct-core` statt aus plugin-eigenen Kopien
- Zahlungsdatensätze folgen dem Cross-Plugin-Schema v1: `version` wird zu `schema_version`,
  `delivered_amount` zu `amount_paid`, und ein Angebot trägt jetzt ein `expiry`
- Destination-Tags kommen aus einem atomaren Zähler je Ziel-Account und nutzen den vollen
  vorzeichenlosen 32-Bit-Bereich des XRPL; die Spalten `destination_tag` und `ledger_index`
  wurden entsprechend verbreitert
- Wechselkurse werden im Object-Cache von Shopware zwischengespeichert, ein kurzer Ausfall der
  Kursquellen unterbricht den Checkout damit nicht mehr
- Neue Einstellungen: RLUSD/USDC lassen sich abschalten, die Gültigkeit eines Kursangebots ist
  konfigurierbar
- Testnet-/Mainnet-Umschalter korrigiert: er las einen Konfigurationsschlüssel, der nie
  gespeichert wurde, und blieb dadurch immer im Testnet
- Ob eine Ledger-Zahlung eine Bestellung begleicht, entscheidet jetzt die `SettlementPolicy` des Cores
  (XRP mit 0,15 % Toleranz, Token exakt vom quotierten Issuer); ein gleichnamiger Token eines anderen
  Issuers gilt nicht mehr als bezahlt. Benötigt `hardcastle/ledger-direct-core` 0.2

# 1.0.0
- Erstveröffentlichung: XRP-, RLUSD- und USDC-Zahlungen direkt über das XRP Ledger annehmen
