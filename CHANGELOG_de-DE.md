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
