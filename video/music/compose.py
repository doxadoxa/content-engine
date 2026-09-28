"""The Avyo film score: an original 100 BPM track in D major, synthesised from
nothing but numpy so there is no sample library and no licence to worry about.

Every cue it lands on (typing, pops, ticks, the publish chime, scene whooshes)
is read from ../src/timeline.json, the same file the animation reads, so the
picture and the sound cannot drift apart.

    python3 music/compose.py public/music.wav
"""

import json
import sys
import wave
from pathlib import Path

import numpy as np

SR = 44100
ROOT = Path(__file__).resolve().parent.parent
T = json.loads((ROOT / "src" / "timeline.json").read_text())

FPS = T["fps"]
BEAT = 60 / T["bpm"]           # 0.6 s
BAR = BEAT * 4                 # 2.4 s
LENGTH = T["duration"] / FPS   # 55.2 s
N = int(round(LENGTH * SR))

rng = np.random.default_rng(7)


def f2s(frame):
    return frame / FPS


def hz(midi):
    return 440.0 * 2 ** ((midi - 69) / 12)


def bus():
    return np.zeros((2, N))


def place(dst, sig, at, gain=1.0, pan=0.0):
    """Mix a mono or stereo signal into `dst` starting at `at` seconds."""
    start = int(round(at * SR))
    if start >= N:
        return
    if sig.ndim == 1:
        left, right = np.cos((pan + 1) * np.pi / 4), np.sin((pan + 1) * np.pi / 4)
        sig = np.vstack([sig * left * np.sqrt(2), sig * right * np.sqrt(2)])
    end = min(N, start + sig.shape[1])
    dst[:, start:end] += sig[:, : end - start] * gain


def band(sig, lo, hi):
    """Brick-wall band filter in the frequency domain; fine for one-shots."""
    spec = np.fft.rfft(sig)
    freqs = np.fft.rfftfreq(len(sig), 1 / SR)
    spec[(freqs < lo) | (freqs > hi)] = 0
    return np.fft.irfft(spec, len(sig))


def env_ar(n, attack, release_at, release):
    t = np.arange(n) / SR
    e = np.clip(t / max(attack, 1e-4), 0, 1)
    tail = np.clip(1 - (t - release_at) / release, 0, 1)
    return e * np.where(t < release_at, 1.0, tail)


# ---------------------------------------------------------------- instruments

def pad(midi, dur):
    n = int((dur + 1.2) * SR)
    t = np.arange(n) / SR
    out = np.zeros((2, n))
    for side, cents in ((0, -8), (1, 8), (0, 3), (1, -3)):
        f = hz(midi) * 2 ** (cents / 1200)
        phase = rng.uniform(0, 2 * np.pi)
        voice = sum(np.sin(2 * np.pi * f * k * t + phase * k) / k ** 1.8 for k in range(1, 7))
        out[side] += voice
    slow = 1 + 0.08 * np.sin(2 * np.pi * 0.25 * t)
    return out * env_ar(n, 0.7, dur, 1.1) * slow * 0.5


def pluck(midi, decay=0.45):
    n = int(decay * 5 * SR)
    t = np.arange(n) / SR
    f = hz(midi)
    body = (np.sin(2 * np.pi * f * t)
            + 0.35 * np.sin(2 * np.pi * 2 * f * t) * np.exp(-t / (decay * 0.35))
            + 0.12 * np.sin(2 * np.pi * 4.2 * f * t) * np.exp(-t / 0.05))
    return body * np.exp(-t / decay) * np.clip(t / 0.003, 0, 1)


def bell(midi, dur):
    n = int((dur + 1.5) * SR)
    t = np.arange(n) / SR
    f = hz(midi) * (1 + 0.003 * np.sin(2 * np.pi * 5.2 * t) * np.clip(t / 0.4, 0, 1))
    body = (np.sin(2 * np.pi * f * t)
            + 0.45 * np.sin(2 * np.pi * 2 * f * t) * np.exp(-t / 0.5)
            + 0.18 * np.sin(2 * np.pi * 3 * f * t) * np.exp(-t / 0.2))
    return body * env_ar(n, 0.012, dur, 0.9) * np.exp(-t / 2.2)


def bass(midi, dur):
    n = int((dur + 0.1) * SR)
    t = np.arange(n) / SR
    f = hz(midi)
    body = np.sin(2 * np.pi * f * t) + 0.3 * np.sin(2 * np.pi * 2 * f * t) + 0.08 * np.sin(2 * np.pi * 3 * f * t)
    return np.tanh(1.4 * body) * env_ar(n, 0.006, dur, 0.07)


def kick():
    n = int(0.5 * SR)
    t = np.arange(n) / SR
    freq = 45 + 95 * np.exp(-t / 0.035)
    phase = 2 * np.pi * np.cumsum(freq) / SR
    click = band(rng.standard_normal(n), 1500, 6000) * np.exp(-t / 0.004) * 0.25
    return np.sin(phase) * np.exp(-t / 0.22) + click


def clap():
    n = int(0.45 * SR)
    t = np.arange(n) / SR
    noise = band(rng.standard_normal(n), 900, 7000)
    e = np.zeros(n)
    for k, off in enumerate((0, 0.011, 0.022)):
        e += np.where(t >= off, np.exp(-(t - off) / (0.012 if k < 2 else 0.14)), 0)
    return noise * e * 0.5


def hat(decay=0.035):
    n = int(0.25 * SR)
    t = np.arange(n) / SR
    return band(rng.standard_normal(n), 7000, 16000) * np.exp(-t / decay)


def crash():
    n = int(3.0 * SR)
    t = np.arange(n) / SR
    return band(rng.standard_normal(n), 3500, 15000) * np.exp(-t / 0.9) * 0.6


def riser(dur):
    n = int(dur * SR)
    t = np.arange(n) / SR
    x = t / dur
    noise = band(rng.standard_normal(n), 800, 12000) * x ** 2.5
    sweep = np.sin(2 * np.pi * np.cumsum(220 * 2 ** (2.5 * x)) / SR) * x ** 3 * 0.25
    return (noise * 0.6 + sweep) * np.clip((1 - x) / 0.01, 0, 1)


def whoosh():
    n = int(0.75 * SR)
    t = np.arange(n) / SR
    peak = 0.42
    e = np.where(t < peak, (t / peak) ** 2, np.exp(-(t - peak) / 0.09))
    return band(rng.standard_normal(n), 400, 5000) * e * 0.5


def key_click():
    n = int(0.03 * SR)
    t = np.arange(n) / SR
    return band(rng.standard_normal(n), 1800, 7000) * np.exp(-t / 0.006) * rng.uniform(0.6, 1.0)


def pop():
    n = int(0.12 * SR)
    t = np.arange(n) / SR
    f = 520 + 700 * np.exp(-t / 0.02)
    return np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t / 0.035)


def ui_click():
    n = int(0.06 * SR)
    t = np.arange(n) / SR
    thump = np.sin(2 * np.pi * 180 * t) * np.exp(-t / 0.015)
    return band(rng.standard_normal(n), 2000, 8000) * np.exp(-t / 0.004) * 0.6 + thump * 0.6


# ------------------------------------------------------------------- harmony

CHORDS = {
    "D": ([54, 57, 62, 66], 38),
    "A": ([52, 57, 61, 64], 45),
    "Bm": ([54, 59, 62, 66], 47),
    "G": ([55, 59, 62, 67], 43),
    "Em": ([55, 59, 64, 67], 40),
}
# One chord per bar, 23 bars.
PROGRESSION = ["D", "Bm", "G", "A", "A",
               "D", "A", "Bm", "G", "D", "A", "Bm", "G",
               "D", "A", "Bm", "G", "Em", "A",
               "D", "Bm", "G", "D"]

MELODY = {  # bar: [(beat, midi, beats)]
    13: [(0, 69, 1.5), (1.5, 66, 0.5), (2, 69, 1), (3, 71, 1)],
    14: [(0, 73, 1.5), (1.5, 71, 0.5), (2, 69, 2)],
    15: [(0, 66, 1), (1, 69, 1), (2, 71, 1.5), (3.5, 69, 0.5)],
    16: [(0, 67, 1), (1, 66, 1), (2, 64, 2)],
    17: [(0, 64, 1), (1, 66, 1), (2, 67, 1), (3, 71, 1)],
    18: [(0, 69, 2.5), (2.5, 71, 0.5), (3, 73, 1)],
    19: [(0, 74, 4)],
}

pads, arps, basses, leads, drums, fx = (bus() for _ in range(6))
kicks = []

for b, name in enumerate(PROGRESSION):
    notes, root = CHORDS[name]
    t0 = b * BAR
    last = b == len(PROGRESSION) - 1

    # Pad: the whole way through, fuller once the band is in.
    for i, m in enumerate(notes):
        place(pads, pad(m, BAR if not last else BAR * 0.5), t0, 0.16 if b < 5 else 0.19)

    # Kalimba arpeggio.
    hi = [m + 12 for m in notes]
    pattern = [0, 2, 1, 3, 2, 1, 3, 2]
    if b in (3, 4):                          # the gap: sparse, waiting
        steps = [(0, hi[0]), (1.5, hi[2]), (3, hi[1])] if b == 3 else [(0, hi[3]), (2, hi[2])]
    elif last:                               # final strum
        steps = [(i * 0.12, m) for i, m in enumerate(hi + [hi[0] + 12])]
    else:
        steps = [(i * 0.5, hi[p]) for i, p in enumerate(pattern)]
    for beat, m in steps:
        vel = 0.9 if beat % 2 == 0 else 0.6
        if b >= 19 and not last:
            vel *= 1 - (b - 19) * 0.2
        place(arps, pluck(m, 0.5 if not last else 1.6), t0 + beat * BEAT, 0.17 * vel,
              pan=0.35 if int(beat * 2) % 2 else -0.35)

    # Bass from the logo onwards.
    if 5 <= b <= 18:
        if b <= 6:
            hits = [(0, 1.5), (2.5, 1.5)]
        else:
            hits = [(0, 0.75), (0.75, 0.75), (1.5, 1), (2.5, 0.5), (3, 0.5), (3.5, 0.5)]
        for beat, length in hits:
            m = root + (12 if beat == 3.5 else 0)
            place(basses, bass(m, length * BEAT * 0.92), t0 + beat * BEAT, 0.24)
    if b in (19, 22):
        place(basses, bass(root, BAR * 0.9), t0, 0.3)

    # Drums.
    if b in (5, 6):
        kick_beats, clap_beats, hat_step = [0, 2.5], [2], 1.0
    elif 7 <= b <= 18:
        kick_beats, clap_beats, hat_step = [0, 1.75, 2, 3.5], [1, 3], 0.5
    else:
        kick_beats, clap_beats, hat_step = [], [], None
    for beat in kick_beats:
        at = t0 + beat * BEAT
        place(drums, kick(), at, 0.44)
        kicks.append(at)
    for beat in clap_beats:
        place(drums, clap(), t0 + beat * BEAT, 0.4)
    if hat_step:
        k = 0
        beat = 0.0
        while beat < 4:
            open_hat = beat % 1 == 0.5
            place(drums, hat(0.07 if open_hat else 0.03), t0 + beat * BEAT,
                  0.16 if open_hat else 0.1, pan=0.25)
            beat += hat_step
            k += 1
        if b >= 9:  # sixteenth shaker once the groove settles
            for s in range(16):
                place(drums, hat(0.02), t0 + s * BEAT / 4, 0.05 * (1.3 if s % 4 == 2 else 1), pan=-0.3)
    if b == 18:  # clap fill into the close
        for s in range(8):
            place(drums, clap(), t0 + (2 + s * 0.25) * BEAT, 0.08 + 0.025 * s)

    # Lead.
    for beat, m, length in MELODY.get(b, []):
        place(leads, bell(m, length * BEAT), t0 + beat * BEAT, 0.2)
        place(leads, bell(m - 12, length * BEAT), t0 + beat * BEAT, 0.05)

# Scene transitions.
place(fx, riser(BAR * 1.0), 4 * BAR, 0.35)
place(fx, crash(), 5 * BAR, 0.5)
place(drums, kick(), 5 * BAR, 0.3)
for scene in ("research", "write", "publish", "measure", "close"):
    place(fx, whoosh(), f2s(T["scenes"][scene][0]) - 0.42, 0.28, pan=-0.2)
place(fx, crash(), 19 * BAR, 0.45)
place(drums, kick(), 19 * BAR, 0.55)

# UI sound effects, straight from the timeline.
q = T["question"]
for i, ch in enumerate(q["query"]):
    if ch != " ":
        place(fx, key_click(), f2s(q["typeStart"] + i * q["framesPerChar"]), 0.22, pan=rng.uniform(-0.2, 0.2))
for i, f in enumerate(q["chips"]):
    place(fx, pop(), f2s(f), 0.12, pan=[-0.5, 0.5, -0.3, 0.3, -0.6, 0.6][i])

r = T["research"]
for f in r["questions"]:
    place(fx, pop(), f2s(f), 0.09)
for i, f in enumerate(r["ticks"]):
    place(fx, pluck(86 + [0, 2, 4][i], 0.18), f2s(f), 0.1)
for f in r["cards"]:
    place(fx, pop(), f2s(f), 0.11, pan=0.3)
place(fx, pluck(93, 0.25), f2s(r["scheduled"]), 0.08)

w = T["write"]
for f in w["art"]:
    place(fx, pop(), f2s(f), 0.08, pan=-0.3)
for i, ch in enumerate(w["title"]):
    if ch != " " and i % 2 == 0:
        place(fx, key_click(), f2s(w["titleStart"] + i * w["titleFramesPerChar"]), 0.16, pan=-0.2)
place(fx, pluck(90, 0.2), f2s(w["badge"]), 0.1)
place(fx, pluck(97, 0.2), f2s(w["badge"]) + 0.08, 0.07)

p = T["publish"]
place(fx, ui_click(), f2s(p["toggle"]), 0.22)
place(fx, ui_click(), f2s(p["approve"]), 0.26)
place(fx, whoosh(), f2s(p["fly"]) - 0.2, 0.14, pan=0.4)
for i, m in enumerate((86, 93, 98)):
    place(fx, bell(m, 0.3), f2s(p["toast"]) + i * 0.07, 0.07)

m_ = T["measure"]
for f in m_["rows"]:
    place(fx, pop(), f2s(f), 0.09, pan=0.3)

c = T["close"]
place(fx, pop(), f2s(c["cta"]), 0.1)

# ----------------------------------------------------------------------- mix

# Sidechain: pads and arps breathe with the kick.
t = np.arange(N) / SR
duck = np.ones(N)
for k in kicks:
    s = int(k * SR)
    e = min(N, s + int(0.35 * SR))
    duck[s:e] = np.minimum(duck[s:e], 1 - 0.4 * np.exp(-(t[s:e] - k) / 0.12))
pads *= duck
arps *= 0.6 + 0.4 * duck

dry = pads + arps + basses + leads + drums + fx
send = pads * 0.35 + arps * 0.6 + leads * 0.7 + drums * 0.08 + fx * 0.35

# Convolution reverb: a decaying stereo noise tail, darkened.
ir_len = int(2.4 * SR)
ir_t = np.arange(ir_len) / SR
ir = np.vstack([band(rng.standard_normal(ir_len), 150, 6500) * np.exp(-ir_t / 0.55) for _ in range(2)])
ir /= np.sqrt((ir ** 2).sum(axis=1, keepdims=True))
size = 1 << int(np.ceil(np.log2(N + ir_len)))
wet = np.vstack([np.fft.irfft(np.fft.rfft(send[ch], size) * np.fft.rfft(ir[ch], size), size)[:N] for ch in range(2)])

mix = dry + wet * 0.55

# Master: gentle saturation, fades, normalise to -1 dBFS.
mix /= np.abs(mix).max()
mix = np.tanh(1.6 * mix) / np.tanh(1.6)
fade_in = np.clip(t / 0.02, 0, 1)
fade_out = np.clip((LENGTH - t) / 1.4, 0, 1) ** 1.5
mix *= fade_in * fade_out
mix *= 10 ** (-1 / 20) / np.abs(mix).max()

out = Path(sys.argv[1] if len(sys.argv) > 1 else ROOT / "public" / "music.wav")
out.parent.mkdir(parents=True, exist_ok=True)
pcm = (np.clip(mix.T, -1, 1) * 32767).astype("<i2")
with wave.open(str(out), "wb") as f:
    f.setnchannels(2)
    f.setsampwidth(2)
    f.setframerate(SR)
    f.writeframes(pcm.tobytes())
print(f"wrote {out} · {LENGTH:.1f}s")
