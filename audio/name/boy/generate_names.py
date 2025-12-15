
import requests
from transliterate import translit

API_KEY = "sk_398474fa55aacbb0e700af28172cc12c80065f513cdd9b53"
VOICE_ID = "jEuZqIqa6rRIk2Tv1oEB"
API_URL = f"https://api.elevenlabs.io/v1/text-to-speech/{VOICE_ID}"

HEADERS = {
    "xi-api-key": API_KEY,
    "Content-Type": "application/json",
    "Accept": "audio/mpeg"
}

def create_payload(name: str):
    ssml_text = (
        "<speak xml:lang='uk-UA'>"
        "<prosody rate='94%' volume='0dB' pitch='-1st'>"
        f" {name} "
        "<break time='150ms'/>"
        "</prosody>"
        "</speak>"
    )
    return {
        "text": ssml_text,
        "model_id": "eleven_multilingual_v2",
        "voice_settings": {
            "stability": 0.85,
            "similarity_boost": 0.36,
            "use_speaker_boost": True,
            "style": 0.5,
            "speed": 1.0
        },
        "language_code": "uk",
        "pronunciation_dictionary_locators": [],
        "seed": None,
        "previous_text": None,
        "next_text": None,
        "previous_request_ids": [],
        "next_request_ids": [],
        "use_pvc_as_ivc": False,
        "apply_text_normalization": "on",
        "hcaptcha_token": ""
    }


def save_audio(filename: str, audio_bytes: bytes):
    with open(filename, "wb") as f:
        f.write(audio_bytes)

def main():
    with open("X1.txt", "r", encoding="utf-8") as file:
        names = [line.strip() for line in file if line.strip()]

    for name in names:
        payload = create_payload(name)
        response = requests.post(API_URL, headers=HEADERS, json=payload)
        if response.status_code == 200:
            latin_name = translit(name, 'uk', reversed=True)
            save_audio(f"{latin_name}.mp3", response.content)
            print(f"Сгенерирован файл: {latin_name}.mp3")
        else:
            print(f"Ошибка при генерации для {name}: {response.status_code} - {response.text}")

if __name__ == "__main__":
    main()