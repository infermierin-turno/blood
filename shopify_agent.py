import os
import json
import requests
from openai import OpenAI

class ShopifyCoffeeAgent:
    def __init__(self, shop_url, client_id, client_secret, openai_api_key):
        self.shop_url = shop_url.rstrip('/')
        self.client_id = client_id
        self.client_secret = client_secret
        self.ai_client = OpenAI(api_key=openai_api_key)
        
        # Genera il token di accesso usando le credenziali OAuth
        self.access_token = self._get_admin_access_token()
        
        self.headers = {
            "Content-Type": "application/json",
            "X-Shopify-Access-Token": self.access_token if self.access_token else ""
        }

    def _get_admin_access_token(self):
        """Ottiene dinamicamente il token di accesso admin usando Client ID e Client Secret."""
        auth_url = f"{self.shop_url}/admin/oauth/access_token"
        payload = {
            "client_id": self.client_id,
            "client_secret": self.client_secret,
            "grant_type": "client_credentials"
        }
        
        try:
            response = requests.post(auth_url, data=payload)
            if response.status_code == 200:
                data = response.json()
                token = data.get("access_token")
                print("[SUCCESSO] Token di accesso Shopify generato correttamente.")
                return token
            else:
                print(f"[AVVISO] OAuth standard non riuscito ({response.status_code}: {response.text}).")
                return self.client_secret
        except Exception as e:
            print(f"Errore durante la richiesta del token Shopify: {e}")
            return None

    def get_products(self, limit=20):
        """Recupera l'elenco dei prodotti dal negozio Shopify."""
        url = f"{self.shop_url}/admin/api/2024-01/products.json?limit={limit}"
        response = requests.get(url, headers=self.headers)
        
        if response.status_code == 200:
            return response.json().get("products", [])
        else:
            print(f"Errore nel recupero prodotti: {response.status_code} - {response.text}")
            return []

    def optimize_coffee_content(self, title, current_body):
        """Usa l'IA per generare HTML del corpo, Meta Title e Meta Description ottimizzati SEO."""
        system_prompt = """
Sei il copywriter e l'esperto ufficiale di Caffè Sansone, una torrefazione artigianale italiana. 
La tua voce è italiana, esperta, cordiale e concreta: artigianale senza essere pomposa, precisa senza essere rigida. Aiuti chi visita lo shop a scegliere, capire e risolvere velocemente, parlando a persone che cercano caffè specialty, miscele per espresso e moka, monorigine e formati pratici. Si sente la cura da micro-torrefazione e l’attenzione per il caffè fatto bene.

REGOLE TASSATIVE PER L'OUTPUT:
Devi restituire esclusivamente un oggetto JSON valido (senza blocchi di markdown ```json o altro, solo il testo grezzo JSON) con questa struttura esatta:
{
  "seo_title": "Stringa di massimo 55-60 caratteri, ottimizzata per Google e per il click",
  "seo_description": "Stringa tra i 140 e i 155 caratteri, persuasiva e ricca di valore per i clienti umani",
  "body_html": "Il codice HTML puro (strutturato con <h2>, <p>, <ul>, <li>, <strong>) con la descrizione dettagliata"
}

Non aggiungere alcun testo prima o dopo il JSON.
"""

        user_prompt = f"""
Ottimizza il seguente prodotto per il nostro e-commerce di caffè.
        
Nome Prodotto: {title}
Descrizione Attuale: {current_body}
"""

        try:
            response = self.ai_client.chat.completions.create(
                model="gpt-4o-mini",
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_prompt}
                ],
                temperature=0.7
            )
            raw_content = response.choices[0].message.content.strip()
            
            # Pulisce eventuali blocchi di codice markdown se presenti per errore
            if raw_content.startswith("```"):
                raw_content = raw_content.split("```")[1]
                if raw_content.startswith("json"):
                    raw_content = raw_content[4:].strip()
                raw_content = raw_content.rstrip("`").strip()

            data = json.loads(raw_content)
            return data
        except Exception as e:
            print(f"Errore durante la generazione o ilparsing JSON dall'IA: {e}")
            return None

    def update_product_seo_and_description(self, product_id, seo_data, tag_to_add="Ottimizzato IA"):
        """Aggiorna su Shopify descrizione HTML, Meta Title, Meta Description e tag."""
        get_url = f"{self.shop_url}/admin/api/2024-01/products/{product_id}.json"
        get_resp = requests.get(get_url, headers=self.headers)
        
        current_tags_str = ""
        if get_resp.status_code == 200:
            product_data = get_resp.json().get("product", {})
            current_tags_str = product_data.get("tags", "")

        tags_list = [t.strip() for t in current_tags_str.split(",")] if current_tags_str else []
        if tag_to_add not in tags_list:
            tags_list.append(tag_to_add)
        
        updated_tags_str = ", ".join(tags_list)

        put_url = f"{self.shop_url}/admin/api/2024-01/products/{product_id}.json"
        payload = {
            "product": {
                "id": product_id,
                "body_html": seo_data.get("body_html"),
                "metafields_global_title_tag": seo_data.get("seo_title"),
                "metafields_global_description_tag": seo_data.get("seo_description"),
                "tags": updated_tags_str
            }
        }
        
        response = requests.put(put_url, json=payload, headers=self.headers)
        
        if response.status_code == 200:
            print(f"[SUCCESSO] Prodotto ID {product_id} ottimizzato con HTML e Meta Tag SEO!")
            return True
        else:
            print(f"[ERRORE] Impossibile aggiornare il prodotto {product_id}: {response.text}")
            return False

    def run_optimization_pipeline(self, limit=3):
        """Esegue il flusso completo: legge i prodotti, li ottimizza con l'IA (SEO + HTML) e li aggiorna."""
        print("Avvio della pipeline SEO & Copywriting per Caffè Sansone...")
        products = self.get_products(limit=limit)
        
        if not products:
            print("Nessun prodotto trovato o errore di connessione.")
            return

        for product in products:
            prod_id = product["id"]
            title = product["title"]
            body_html = product.get("body_html", "")
            
            print(f"\nElaborazione in corso per: '{title}'...")
            seo_data = self.optimize_coffee_content(title, body_html)
            
            if seo_data:
                print(f" -> Meta Title generato ({len(seo_data.get('seo_title', ''))} caratteri): {seo_data.get('seo_title')}")
                print(f" -> Meta Description generata ({len(seo_data.get('seo_description', ''))} caratteri)")
                self.update_product_seo_and_description(prod_id, seo_data, tag_to_add="Ottimizzato IA")
