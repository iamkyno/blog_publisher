import requests
import re
import json
import nltk
from fastapi import FastAPI, HTTPException
from dotenv import load_dotenv
import os
import ollama
import logging

# Load environment variables
load_dotenv()

# WordPress API Credentials
WP_SITE = os.getenv("WP_SITE")  # Example: "https://yourwebsite.com"
WP_USERNAME = os.getenv("WP_USERNAME")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD")

# Set up FastAPI
app = FastAPI()

# Ensure NLTK dependencies are downloaded
nltk.download("punkt")

logging.basicConfig(level=logging.DEBUG)

def clean_content(content):
    """
    Cleans the blog content by removing unwanted phrases and formatting.
    """
    unwanted_phrases = [
        "Here is the formatted blog post:",
        "Let me know if you need any further assistance!",
    ]
    for phrase in unwanted_phrases:
        content = content.replace(phrase, "")
    return content.strip()

def process_with_llama3(text):
    """
    Calls Ollama's local LLaMA 3 model to process blog content.
    """
    prompt = f"""
    Format the following blog post:
    - Add appropriate <p> tags.
    - Detect and format list items.
    - Do not create your own title.

    Blog Content:
    {text}
    """
    
    response = ollama.chat(model="llama3", messages=[{"role": "user", "content": prompt}])
    
    if "message" in response:
        formatted_content = response["message"]["content"]
        formatted_content = formatted_content.replace('</p>', '</p>\n')
        return formatted_content
    return None

def spell_check(text):
    words = nltk.word_tokenize(text)
    return " ".join(words)

def get_internal_links():
    """
    Fetch existing post titles and links from WordPress to insert internal links.
    """
    url = f"{WP_SITE}/wp-json/wp/v2/posts?per_page=100"
    auth = (WP_USERNAME, WP_APP_PASSWORD)
    response = requests.get(url, auth=auth)
    if response.status_code == 200:
        posts = response.json()
        return {post["title"]["rendered"]: post["link"] for post in posts}
    return {}

def insert_internal_links(content, links):
    for title, url in links.items():
        content = re.sub(rf"\b{title}\b", f'<a href="{url}">{title}</a>', content, flags=re.IGNORECASE)
    return content

def fetch_tag_ids(tags):
    """
    Convert tag names to their corresponding tag IDs from WordPress.
    """
    url = f"{WP_SITE}/wp-json/wp/v2/tags?search="
    tag_ids = []
    for tag in tags:
        response = requests.get(url + tag, auth=(WP_USERNAME, WP_APP_PASSWORD))
        if response.status_code == 200:
            tags_data = response.json()
            if tags_data:
                tag_ids.append(tags_data[0]["id"])
    return tag_ids

def publish_to_wordpress(title, content, meta_description, tag_ids):
    url = f"{WP_SITE}/wp-json/wp/v2/pages"
    auth = (WP_USERNAME, WP_APP_PASSWORD)
    
    post_data = {
        "title": title,
        "content": content,
        "status": "publish",
        "meta": {"yoast_wpseo_metadesc": meta_description},
        "tags": tag_ids
    }
    
    response = requests.post(url, json=post_data, auth=auth)
    return response.json() if response.status_code == 201 else None

@app.post("/upload-blog")
def upload_blog(title: str, blog_content: str):
    if not blog_content:
        raise HTTPException(status_code=400, detail="No content provided")

    cleaned_content = clean_content(blog_content)
    formatted_content = process_with_llama3(cleaned_content)
    if not formatted_content:
        raise HTTPException(status_code=500, detail="LLaMA 3 processing failed")

    checked_content = spell_check(formatted_content)
    internal_links = get_internal_links()
    final_content = insert_internal_links(formatted_content, internal_links)

    tags = title.split()
    tag_ids = fetch_tag_ids(tags)
    
    meta_description = f"Learn more about {title} in this detailed blog post."
    
    result = publish_to_wordpress(title, final_content, meta_description, tag_ids)
    if result:
        return {"message": "Blog post published successfully", "post": result}
    else:
        raise HTTPException(status_code=500, detail="Failed to publish post")
