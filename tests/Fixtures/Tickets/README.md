# Gemeinsame Ticket-Hashvektoren

`golden-vectors.json` enthält festgeschriebene, implementierungsunabhängig zu konsumierende Referenzwerte für beide V1-Profile. Sie wurden einmalig aus den unveränderten Fixture-Bytes nach dem Vertrag aus Plan §5.2 hergeleitet:

1. Frontmatter restriktiv als YAML-Mapping lesen und ausschließlich `status` entfernen.
2. Strings und Schlüssel nach NFC normalisieren und das Mapping mit der vorhandenen RFC-8785-Teilmenge serialisieren.
3. Den Body von CRLF auf LF normalisieren, nach NFC normalisieren, ausschließlich finale LF entfernen und genau ein LF anhängen.
4. `AI6-TICKET-CONTRACT-V1`, ein NUL-Byte, die beiden unsigned 64-bit Big-Endian-Längen und anschließend JCS- beziehungsweise Body-Bytes rahmen.
5. SHA-256 als kleingeschriebenes Hex ausgeben.

Die Tests lesen diese Werte nur als erwartete Konstanten; sie erzeugen oder aktualisieren sie nicht über `TicketContractHasher`. Darstellungsäquivalenzen, eine fehlende finale LF sowie konfliktwirksame Änderungen werden separat gegen dieselben Fixtures geprüft. Änderungen an Algorithmus oder Referenzwerten benötigen eine Plan-/Vertragsentscheidung und dürfen nicht zur Anpassung an eine Implementierung regeneriert werden.
