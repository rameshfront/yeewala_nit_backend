import sys
import os
import cv2

def extract_thumbnail(video_url, output_path, preferred_time_ms=500):
    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    
    cap = cv2.VideoCapture(video_url)
    if not cap.isOpened():
        print(f"Error: Could not open video: {video_url}", file=sys.stderr)
        return False
        
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
