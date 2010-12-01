Name: xournal
Version: 0.4.5
Release: 2
Summary: An notetaking application
License: GPL
Group: Applications/Productivity
Source: %{name}-%{version}.tar.gz
BuildRoot: %{_tmppath}/%{name}-%{version}-root

BuildRequires: autoconf
BuildRequires: automake
BuildRequires: gtk2-devel
BuildRequires: libgnomecanvas-devel
BuildRequires: poppler-glib-devel

%description
Xournal is an application for notetaking, sketching, keeping a journal and
annotating PDFs. Xournal aims to provide superior graphical quality (subpixel
resolution) and overall functionality.

%prep
%setup -n %{name}-%{version}

%build
%configure
make

%install
%{__rm} -rf %{buildroot}
%{__make} install DESTDIR=%{buildroot}

install -d %{buildroot}/usr/share/applications/
install %{name}.desktop %{buildroot}/usr/share/applications/%{name}.desktop

%clean
%{__rm} -rf %{buildroot}

%files
%defattr(-,root,root,-)
/usr/share/applications/%{name}.desktop
/usr/bin/xournal
/usr/share/xournal/html-doc/manual.html
/usr/share/xournal/html-doc/pixmaps
/usr/share/xournal/html-doc/screenshot.png
/usr/share/xournal/pixmaps/black.png
/usr/share/xournal/pixmaps/blue.png
/usr/share/xournal/pixmaps/default-pen.png
/usr/share/xournal/pixmaps/eraser.png
/usr/share/xournal/pixmaps/fullscreen.png
/usr/share/xournal/pixmaps/gray.png
/usr/share/xournal/pixmaps/green.png
/usr/share/xournal/pixmaps/hand.png
/usr/share/xournal/pixmaps/highlighter.png
/usr/share/xournal/pixmaps/lasso.png
/usr/share/xournal/pixmaps/lightblue.png
/usr/share/xournal/pixmaps/lightgreen.png
/usr/share/xournal/pixmaps/magenta.png
/usr/share/xournal/pixmaps/medium.png
/usr/share/xournal/pixmaps/orange.png
/usr/share/xournal/pixmaps/pencil.png
/usr/share/xournal/pixmaps/rect-select.png
/usr/share/xournal/pixmaps/recycled.png
/usr/share/xournal/pixmaps/red.png
/usr/share/xournal/pixmaps/ruler.png
/usr/share/xournal/pixmaps/shapes.png
/usr/share/xournal/pixmaps/stretch.png
/usr/share/xournal/pixmaps/text-tool.png
/usr/share/xournal/pixmaps/thick.png
/usr/share/xournal/pixmaps/thin.png
/usr/share/xournal/pixmaps/white.png
/usr/share/xournal/pixmaps/xoj.svg
/usr/share/xournal/pixmaps/xournal.png
/usr/share/xournal/pixmaps/xournal.svg
/usr/share/xournal/pixmaps/yellow.png
