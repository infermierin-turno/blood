import os
import json
import requests
from fastapi import FastAPI, Form
from fastapi.responses import HTMLResponse
from shopify_agent import ShopifyCoffeeAgent

app = FastAPI()

@app.get("/", response_class=HTMLResponse)
def preview_shopify_descriptions():
    shop_url = os.getenv("SHOP_URL")
    client_id = os.getenv("SHOPIFY_CLIENT_ID")
    client_secret = os.getenv("SHOPIFY_CLIENT_SECRET")
    openai_api_key = os.getenv("OPENAI_API_KEY")

    if not shop_url or not client_id or not client_secret or not openai_api_key:
        return "<h3>[ERRORE] Mancano una o più variabili d'ambiente richieste.</h3>"

    agent = ShopifyCoffeeAgent(
        shop_url=shop_url,
        client_id=client_id,
        client_secret=client_secret,
        openai_api_key=openai_api_key
    )

    products = agent.get_products(limit=20)
    if not products:
        return "<h3>[AVVISO] Nessun prodotto trovato su Shopify.</h3>"

    tag_filtro = "Ottimizzato IA"
    prodotti_da_elaborare = []

    for product in products:
        tags_raw = product.get("tags", "")
        tags_list = [t.strip() for t in tags_raw.split(",")] if isinstance(tags_raw, str) else (tags_raw or [])
        if tag_filtro not in tags_list:
            prodotti_da_elaborare.append(product)

    if not prodotti_da_elaborare:
        return """
        <div style="font-family: Arial; margin: 40px; text-align: center;">
            <h2 style="color: #059669;">Tutti i prodotti analizzati hanno già il tag 'Ottimizzato IA'!</h2>
            <p>Ottimo lavoro, il catalogo è completamente aggiornato.</p>
        </div>
        """

    batch = prodotti_da_elaborare[:3]

    html_content = """
    <html>
        <head>
            <title>Anteprima e Ottimizzazione SEO & IA Shopify</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; background: #f9f9f9; color: #333; }
                .product-box { background: #fff; border: 1px solid #ddd; padding: 25px; margin-bottom: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
                h2 { color: #b91c1c; font-size: 20px; }
                .preview-section { display: flex; gap: 20px; margin-top: 15px; }
                .column { flex: 1; background: #fdfdfd; border: 1px solid #eee; padding: 15px; border-radius: 6px; overflow-x: auto; max-height: 250px; }
                .column h4 { margin-top: 0; color: #555; border-bottom: 2px solid #ddd; padding-bottom: 8px; }
                .seo-box { background: #e0f2fe; border: 1px solid #bae6fd; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 14px; }
                .btn-container { text-align: center; margin-top: 40px; }
                .btn-apply { background-color: #2563eb; color: white; padding: 14px 28px; font-size: 16px; font-weight: bold; border: none; border-radius: 6px; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .btn-apply:hover { background-color: #1d4ed8; }
            </style>
        </head>
        <body>
            <h1>Anteprima Batch Corrente (3 Prodotti - SEO & IA)</h1>
            <p>Verifica i meta tag e le descrizioni generate dall'IA. Cliccando su "Applica Modifiche", il sistema aggiornerà Shopify con i dati SEO completi e il tag <strong>Ottimizzato IA</strong>.</p>
            <form action="/shopify/applica-batch" method="POST">
    """

    for product in batch:
        product_id = product.get("id")
        title = product.get("title")
        current_body = product.get("body_html", "")
        
        seo_data = agent.optimize_coffee_content(title, current_body)
        if not seo_data:
            seo_data = {
                "seo_title": title,
                "seo_description": "",
                "body_html": current_body
            }

        cleaned_html = seo_data.get("body_html", "").replace("```html", "").replace("```", "").strip()
        seo_title = seo_data.get("seo_title", "")
        seo_desc = seo_data.get("seo_description", "")

        html_content += f"""
            <div class="product-box">
                <h2>{title} (ID: {product_id})</h2>
                <input type="hidden" name="product_ids" value="{product_id}">
                <input type="hidden" name="product_titles" value="{title}">
                <input type="hidden" name="seo_titles" value="{seo_title.replace('"', '&quot;')}">
                <input type="hidden" name="seo_descriptions" value="{seo_desc.replace('"', '&quot;')}">
                <input type="hidden" name="optimized_bodies" value="{cleaned_html.replace('"', '&quot;')}">
                
                <div class="seo-box">
                    <strong>Meta Title ({len(seo_title)} caratteri):</strong> {seo_title}<br><br>
                    <strong>Meta Description ({len(seo_desc)} caratteri):</strong> {seo_desc}
                </div>

                <div class="preview-section">
                    <div class="column">
                        <h4>Descrizione Attuale</h4>
                        <div>{current_body if current_body else '<em>Nessuna descrizione presente</em>'}</div>
                    </div>
                    <div class="column">
                        <h4>Nuova Anteprima HTML Generata</h4>
                        <div>{cleaned_html}</div>
                    </div>
                </div>
            </div>
        """

    html_content += """
                <div class="btn-container">
                    <button type="submit" class="btn-apply">🚀 Applica SEO, HTML e Passa ai Successivi 3</button>
                </div>
            </form>
        </body>
    </html>
    """

    return html_content


@app.post("/applica-batch", response_class=HTMLResponse)
def applica_batch(
    product_ids: list[str] = Form(...), 
    product_titles: list[str] = Form(...), 
    seo_titles: list[str] = Form(...),
    seo_descriptions: list[str] = Form(...),
    optimized_bodies: list[str] = Form(...)
):
    shop_url = os.getenv("SHOP_URL")
    client_id = os.getenv("SHOPIFY_CLIENT_ID")
    client_secret = os.getenv("SHOPIFY_CLIENT_SECRET")
    openai_api_key = os.getenv("OPENAI_API_KEY")

    agent = ShopifyCoffeeAgent(
        shop_url=shop_url,
        client_id=client_id,
        client_secret=client_secret,
        openai_api_key=openai_api_key
    )

    risultati_html = """
    <html>
        <head>
            <title>Risultato Aggiornamento Shopify</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; background: #f9f9f9; color: #333; }
                .success-box { background: #fff; border-left: 6px solid #059669; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; }
                a { display: inline-block; margin-top: 20px; background-color: #059669; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; }
                a:hover { background-color: #047857; }
            </style>
        </head>
        <body>
            <h1>Risultato Applicazione Batch (SEO & HTML)</h1>
    """

    for pid, title, s_title, s_desc, n_body in zip(product_ids, product_titles, seo_titles, seo_descriptions, optimized_bodies):
        try:
            seo_payload = {
                "seo_title": s_title,
                "seo_description": s_desc,
                "body_html": n_body
            }
            agent.update_product_seo_and_description(pid, seo_payload, tag_to_add="Ottimizzato IA")
            risultati_html += f"""
                <div class="success-box">
                    <h3 style="color: #059669; margin-top: 0;">Aggiornato con successo: {title}</h3>
                    <p>ID Shopify: {pid} — Meta Title, Meta Description e Tag 'Ottimizzato IA' applicati correttamente.</p>
                </div>
            """
        except Exception as e:
            risultati_html += f"""
                <div class="success-box" style="border-left-color: #dc2626;">
                    <h3 style="color: #dc2626; margin-top: 0;">Errore per: {title}</h3>
                    <p>Dettaglio errore: {str(e)}</p>
                </div>
            """

    risultati_html += """
            <a href="/shopify/">🔄 Torna all'anteprima per elaborare i prossimi 3 prodotti</a>
        </body>
    </html>
    """

    return risultati_html
