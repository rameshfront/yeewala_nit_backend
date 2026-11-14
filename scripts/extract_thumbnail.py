import sys
import os
import cv2
import tempfile
import urllib.request
import time

def extract_thumbnail(video_url, output_path, preferred_time_ms=500):
    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    
    temp_file = None
    cap = None
    
    # Attempt 1: Direct cv2.VideoCapture (fast for local files or direct streams)
    try:
        cap = cv2.VideoCapture(video_url)
    except Exception as e:
        cap = None

    # Attempt 2: If direct open failed or didn't open and it's an HTTP/HTTPS URL, download temporarily
    if (cap is None or not cap.isOpened()) and (video_url.startswith("http://") or video_url.startswith("https://")):
        for attempt in range(1, 4):
            try:
                fd, temp_file = tempfile.mkstemp(suffix=".mp4")
                os.close(fd)
                req = urllib.request.Request(
                    video_url,
                    headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'}
                )
                with urllib.request.urlopen(req, timeout=30) as resp, open(temp_file, 'wb') as out_f:
                    while True:
                        chunk = resp.read(65536)
                        if not chunk:
                            break
                        out_f.write(chunk)
                
                cap = cv2.VideoCapture(temp_file)
                if cap.isOpened():
                    break
            except Exception as dl_err:
                print(f"Fallback download attempt {attempt} failed: {dl_err}", file=sys.stderr)
                time.sleep(1)

    if cap is None or not cap.isOpened():
        if temp_file and os.path.exists(temp_file):
            try:
                os.remove(temp_file)
            except Exception:
                pass
        print(f"Error: Could not open video: {video_url}", file=sys.stderr)
        return False
        
    try:
        total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        fps = cap.get(cv2.CAP_PROP_FPS) or 30
        duration_ms = (total_frames / fps) * 1000 if total_frames > 0 and fps > 0 else 5000
        
        # Try preferred time (e.g. 500ms = 0.5s)
        target_ms = min(preferred_time_ms, duration_ms * 0.5)
        cap.set(cv2.CAP_PROP_POS_MSEC, target_ms)
        ret, frame = cap.read()
        
        # If dark frame (fade in), try a few points: 1s, 2s, 3s
        attempts = [1000, 2000, 3000, 0]
        for ms in attempts:
            if ret and frame is not None and frame.mean() >= 15:
                break
            cap.set(cv2.CAP_PROP_POS_MSEC, ms)
            ret, frame = cap.read()
            
        cap.release()
        
        if not ret or frame is None:
            print(f"Error: Could not read frame from video: {video_url}", file=sys.stderr)
            return False
            
        success = cv2.imwrite(output_path, frame, [int(cv2.IMWRITE_JPEG_QUALITY), 85])
        if success:
            print(f"Successfully extracted thumbnail: {output_path}")
            return True
        else:
            print(f"Error: Failed to write image to: {output_path}", file=sys.stderr)
            return False
    finally:
        if temp_file and os.path.exists(temp_file):
            try:
                os.remove(temp_file)
            except Exception:
                pass

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python extract_thumbnail.py <video_url> <output_path> [preferred_time_ms]", file=sys.stderr)
        sys.exit(1)
        
    video_url = sys.argv[1]
    output_path = sys.argv[2]
    pref_ms = int(sys.argv[3]) if len(sys.argv) > 3 else 500
    
    if extract_thumbnail(video_url, output_path, pref_ms):
        sys.exit(0)
    else:
        sys.exit(1)
