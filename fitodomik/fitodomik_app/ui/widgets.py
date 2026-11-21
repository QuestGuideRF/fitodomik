from __future__ import annotations
import io
import tkinter as tk
from pathlib import Path
from tkinter import ttk
from typing import Any, Callable
from fitodomik_app .core .paths import ICONS_DIR
from .theme import Palette
def _load_pillow ():
    from PIL import Image as pil_image ,ImageTk as pil_imagetk
    return pil_image ,pil_imagetk
class IconStore :
    def __init__ (self ,size :int =28 )->None :
        self .size =size
        self ._cache :dict [tuple [str ,int ],object ]={}
    def set_size (self ,size :int )->None :
        self .size =size
    def get (self ,name :str ,size :int |None =None )->Any |None :
        target_size =size or self .size
        key =(name ,target_size )
        if key in self ._cache :
            return self ._cache [key ]
        path =ICONS_DIR /f"{name }.png"
        if not path .exists ():
            return None
        pil_image ,pil_imagetk =_load_pillow ()
        image =pil_image .open (path ).convert ("RGBA")
        image .thumbnail ((target_size ,target_size ),pil_image .LANCZOS )
        photo =pil_imagetk .PhotoImage (image )
        self ._cache [key ]=photo
        return photo
    def from_bytes (self ,data :bytes ,size :int )->Any |None :
        try :
            pil_image ,pil_imagetk =_load_pillow ()
            image =pil_image .open (io .BytesIO (data )).convert ("RGBA")
            image .thumbnail ((size ,size ),pil_image .LANCZOS )
            return pil_imagetk .PhotoImage (image )
        except Exception :
            return None
class Card (ttk .Frame ):
    def __init__ (
    self ,
    parent ,
    title :str ="" ,
    palette :Palette |None =None ,
    padding :int =12 ,
    title_font :tuple |None =None ,
    ):
        super ().__init__ (parent ,style ="Card.TFrame",padding =padding )
        self .title_label :ttk .Label |None =None
        self ._padding =padding
        if title :
            self .title_label =ttk .Label (self ,text =title ,style ="Surface.TLabel",font =title_font or ("DejaVu Sans",11 ,"bold"))
            self .title_label .pack (anchor ="w",pady =(0 ,6 ))
    def set_padding (self ,padding :int )->None :
        self ._padding =padding
        try :
            if self .winfo_exists ():
                self .configure (padding =padding )
        except tk .TclError :
            pass
    def set_title_font (self ,font :tuple )->None :
        try :
            if self .title_label and self .title_label .winfo_exists ():
                self .title_label .configure (font =font )
        except tk .TclError :
            pass
class StatusPill (tk .Canvas ):
    def __init__ (self ,parent ,palette :Palette ,width :int =92 ,height :int =28 ,font_size :int =10 ):
        super ().__init__ (parent ,width =width ,height =height ,highlightthickness =0 ,bg =palette .surface )
        self .palette =palette
        self .width =width
        self .height =height
        self .font_size =font_size
        self ._state_signature =None
        self ._dot_id =self .create_oval (0 ,0 ,0 ,0 )
        self ._text_id =self .create_text (0 ,0 ,anchor ="w")
        self .set_state (False )
    def set_size (self ,width :int ,height :int ,font_size :int |None =None )->None :
        self .width =width
        self .height =height
        if font_size is not None :
            self .font_size =font_size
        self .configure (width =width ,height =height )
    def set_state (self ,enabled :bool ,text :str |None =None )->None :
        color =self .palette .good if enabled else self .palette .danger
        label =text if text is not None else ("Вкл"if enabled else "Выкл")
        signature =(color ,label ,self .palette .surface ,self .palette .text ,self .width ,self .height ,self .font_size )
        if signature ==self ._state_signature :
            return
        self ._state_signature =signature
        self .configure (bg =self .palette .surface )
        dot =max (10 ,min (14 ,int (self .height *0.46 )))
        top =max (4 ,int ((self .height -dot )/2 ))
        left =max (6 ,int (self .height *0.28 ))
        self .coords (self ._dot_id ,left ,top ,left +dot ,top +dot )
        self .itemconfigure (self ._dot_id ,fill =color ,outline =color )
        self .coords (self ._text_id ,left +dot +8 ,self .height /2 )
        self .itemconfigure (self ._text_id ,text =label ,fill =self .palette .text ,font =("DejaVu Sans",self .font_size ,"bold"))
    def set_palette (self ,palette :Palette )->None :
        self .palette =palette
        self .configure (bg =palette .surface )
class ScrollableFrame (ttk .Frame ):
    def __init__ (self ,parent ,palette :Palette ,canvas_bg :str |None =None ,inner_style :str ="App.TFrame"):
        super ().__init__ (parent ,style ="App.TFrame")
        self .palette =palette
        self .canvas_bg =canvas_bg
        self .inner_style =inner_style
        self .canvas =tk .Canvas (self ,borderwidth =0 ,highlightthickness =0 ,bg =canvas_bg or palette .bg )
        self .scrollbar =ttk .Scrollbar (self ,orient ="vertical",command =self .canvas .yview )
        self .inner =ttk .Frame (self .canvas ,style =inner_style )
        self .window =self .canvas .create_window ((0 ,0 ),window =self .inner ,anchor ="nw")
        self .canvas .configure (yscrollcommand =self .scrollbar .set )
        self .canvas .pack (side ="left",fill ="both",expand =True )
        self .scrollbar .pack (side ="right",fill ="y")
        self .inner .bind ("<Configure>",self ._on_configure )
        self .canvas .bind ("<Configure>",self ._on_canvas_configure )
        self .canvas .bind ("<MouseWheel>",self ._on_mousewheel )
        self .canvas .bind ("<Button-4>",self ._on_mousewheel )
        self .canvas .bind ("<Button-5>",self ._on_mousewheel )
        self .canvas .bind ("<ButtonPress-1>",self ._on_touch_start )
        self .canvas .bind ("<B1-Motion>",self ._on_touch_move )
        self ._touch_y =0
        self ._bound_widgets :set [str ]=set ()
    def _on_configure (self ,event )->None :
        self .canvas .configure (scrollregion =self .canvas .bbox ("all"))
        self ._bind_scroll_children (self .inner )
    def _on_canvas_configure (self ,event )->None :
        self .canvas .itemconfigure (self .window ,width =event .width )
    def _on_mousewheel (self ,event )->None :
        try :
            if not self .winfo_exists ()or not self .canvas .winfo_exists ():
                return
            if not self .winfo_viewable ()or not self ._pointer_inside ():
                return
        except tk .TclError :
            return
        if getattr (event ,"num",None )==4 :
            delta =-1
        elif getattr (event ,"num",None )==5 :
            delta =1
        else :
            delta =int (-1 *(event .delta /120 ))
        try :
            self .canvas .yview_scroll (delta ,"units")
        except tk .TclError :
            pass
    def _on_touch_start (self ,event )->None :
        self ._touch_y =event .y_root
    def _on_touch_move (self ,event )->None :
        try :
            if not self .canvas .winfo_exists ():
                return
        except tk .TclError :
            return
        delta =self ._touch_y -event .y_root
        if abs (delta )>=4 :
            self .canvas .yview_scroll (int (delta /4 ),"units")
            self ._touch_y =event .y_root
    def set_palette (self ,palette :Palette )->None :
        self .palette =palette
        self .configure (style ="App.TFrame")
        self .canvas .configure (bg =self .canvas_bg or palette .bg )
        self .inner .configure (style =self .inner_style )
    def _bind_scroll_children (self ,widget :Any )->None :
        widget_id =str (widget )
        if widget_id not in self ._bound_widgets :
            widget .bind ("<ButtonPress-1>",self ._on_touch_start ,add ="+")
            widget .bind ("<B1-Motion>",self ._on_touch_move ,add ="+")
            widget .bind ("<MouseWheel>",self ._on_mousewheel ,add ="+")
            widget .bind ("<Button-4>",self ._on_mousewheel ,add ="+")
            widget .bind ("<Button-5>",self ._on_mousewheel ,add ="+")
            self ._bound_widgets .add (widget_id )
            widget .bind ("<Destroy>",lambda event ,key =widget_id :self ._bound_widgets .discard (key ),add ="+")
        for child in widget .winfo_children ():
            self ._bind_scroll_children (child )
    def _pointer_inside (self )->bool :
        x =self .winfo_pointerx ()
        y =self .winfo_pointery ()
        left =self .winfo_rootx ()
        top =self .winfo_rooty ()
        return left <=x <=left +self .winfo_width ()and top <=y <=top +self .winfo_height ()
def clear_frame (frame :tk .Widget )->None :
    for child in frame .winfo_children ():
        child .destroy ()
def form_row (parent ,label :str ,widget_factory :Callable [[ttk .Frame ],tk .Widget ])->tk .Widget :
    row =ttk .Frame (parent ,style ="App.TFrame")
    row .pack (fill ="x",pady =4 )
    ttk .Label (row ,text =label ).pack (side ="left",padx =(0 ,8 ))
    widget =widget_factory (row )
    widget .pack (side ="right",fill ="x",expand =True )
    return widget
def load_resized_photo (path :Path ,max_size :tuple [int ,int ])->Any :
    _pil_image ,pil_imagetk =_load_pillow ()
    return pil_imagetk .PhotoImage (prepare_resized_image (path ,max_size ))
def prepare_resized_image (path :Path ,max_size :tuple [int ,int ])->Any :
    from PIL import Image as pil_image
    with pil_image .open (path )as source :
        image =source .convert ("RGB")
    image .thumbnail (max_size ,pil_image .Resampling .LANCZOS )
    return image
