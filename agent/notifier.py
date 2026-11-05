"""OSG Agent · ventana temporal de notificación, sin bandeja ni icono residente."""
import argparse
import base64
import tkinter as tk
from tkinter import ttk


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--message', required=True)
    args = parser.parse_args()
    try:
        data = base64.b64decode(args.message, altchars=b'-_', validate=True)
        if len(data) > 2000:
            raise ValueError('Mensaje demasiado extenso')
        message = data.decode('utf-8')
        if not 1 <= len(message.strip()) <= 500:
            raise ValueError('Longitud inválida')
    except (ValueError, UnicodeError) as exc:
        parser.error(str(exc))

    window = tk.Tk()
    acknowledged = False
    def close_acknowledged() -> None:
        nonlocal acknowledged
        acknowledged = True
        window.destroy()
    window.title('OSG Agent · Mensaje del administrador')
    window.geometry('500x280')
    window.minsize(400, 230)
    window.resizable(True, True)
    window.attributes('-topmost', True)
    root = ttk.Frame(window, padding=24)
    root.pack(expand=True, fill='both')
    ttk.Label(root, text='OSG Agent', font=('Segoe UI', 16, 'bold')).pack(anchor='w', pady=(0, 12))
    ttk.Label(root, text=message, wraplength=435, justify='left', font=('Segoe UI', 11)).pack(anchor='w', fill='both', expand=True)
    ttk.Label(root, text='Creado por Ignacio Sanguina · © 2026', foreground='#777777', font=('Segoe UI', 8)).pack(side='bottom', anchor='w', pady=(10, 0))
    ttk.Button(root, text='Cerrar', command=close_acknowledged).pack(side='bottom', anchor='e')
    # This is not a lock-screen: Windows always allows the user to end an app.
    window.protocol('WM_DELETE_WINDOW', lambda: None)
    window.mainloop()
    return 0 if acknowledged else 1


if __name__ == '__main__':
    raise SystemExit(main())
