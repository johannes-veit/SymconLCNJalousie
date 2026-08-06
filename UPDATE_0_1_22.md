# Update 0.1.22

- Reale LCN-/GT8-Fahrten haben Vorrang vor zeitgleichen Symcon-Aufträgen.
- Symcon sendet während einer erkannten externen Fahrt keinen STOP und keinen Richtungsbefehl.
- Bei unbekannter Referenz bleibt eine externe Fahrt bis zum realen Relais-AUS unangetastet.
- Nach vollständiger richtungsabhängiger Laufzeit plus Referenzreserve wird die erreichte Endlage automatisch referenziert, ohne das Relais auszuschalten.
- Reale Relaisereignisse bleiben auch bei verriegelter/deaktivierter Symcon-Automatik aktiv; lokale LCN-Bedienung bleibt unabhängig.
