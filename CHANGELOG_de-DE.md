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
